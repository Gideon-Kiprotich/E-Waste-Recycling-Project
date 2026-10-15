<?php

namespace App\Support\Routing;

use InvalidArgumentException;

class Planner
{
    public const VERSION = 'NEAREST-FEASIBLE-2OPT-1.0';

    public function matrix(array $jobs, array $depot, float $speed): array
    {
        $points = ['depot' => $depot];
        foreach ($jobs as $job) {
            $points[$job['id']] = $job;
        }
        $matrix = [];
        foreach ($points as $a => $p) {
            foreach ($points as $b => $q) {
                $lat = deg2rad((float) $q['latitude'] - (float) $p['latitude']);
                $lon = deg2rad((float) $q['longitude'] - (float) $p['longitude']);
                $h = sin($lat / 2) ** 2 + cos(deg2rad((float) $p['latitude'])) * cos(deg2rad((float) $q['latitude'])) * sin($lon / 2) ** 2;
                $km = 6371.0088 * 2 * asin(sqrt(min(1, max(0, $h))));
                $matrix[$a][$b] = ['km' => $km, 'seconds' => $km / $speed * 3600];
            }
        }

        return $matrix;
    }

    public function compare(array $snapshot, ?float $deadline = null, ?callable $clock = null): array
    {
        $clock ??= fn () => microtime(true);
        $start = $clock();
        $deadline ??= $start + 10;
        $this->inputs($snapshot);
        $results = [];
        foreach (['baseline', 'proposed'] as $method) {
            $results[$method] = $this->construct($snapshot, $method, $deadline, $clock);
            $this->validate($snapshot, $results[$method]);
        }
        $same = $results['baseline']['served'] === $results['proposed']['served'];
        $km = $results['baseline']['km'];
        $results['comparison'] = ['same_coverage' => $same, 'improvement_percent' => $same && $km > 0 ? 100 * ($km - $results['proposed']['km']) / $km : null];
        $results['calculation_seconds'] = max(0, $clock() - $start);

        return $results;
    }

    private function construct(array $s, string $method, float $deadline, callable $clock): array
    {
        $remaining = array_column($s['jobs'], null, 'id');
        $vehicles = $s['vehicles'];
        usort($vehicles, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $routes = [];
        $budget = false;
        $iterations = 0;
        $iterationLimit = false;
        foreach ($vehicles as $vehicle) {
            $stops = [];
            while ($remaining) {
                if ($clock() >= $deadline) {
                    $budget = true;
                    break;
                }
                $candidates = array_filter($remaining, fn ($j) => $j['collector_id'] === $vehicle['collector_id'] && $this->feasible($s, [...$stops, $j['id']], $vehicle));
                if (! $candidates) {
                    break;
                }
                $last = $stops ? end($stops) : 'depot';
                uasort($candidates, fn ($a, $b) => ($method === 'baseline' ? strcmp($a['submitted_at'], $b['submitted_at']) : ($s['matrix'][$last][$a['id']]['km'] <=> $s['matrix'][$last][$b['id']]['km'])) ?: strcmp($a['id'], $b['id']));
                $chosen = array_key_first($candidates);
                $stops[] = $chosen;
                unset($remaining[$chosen]);
            }
            if ($stops) {
                $routes[] = ['vehicle_id' => $vehicle['id'], 'collector_id' => $vehicle['collector_id'], 'stops' => $stops] + $this->cost($s, $stops);
            }
        }
        if ($method === 'proposed') {
            foreach ($routes as &$route) {
                $vehicle = collect($vehicles)->firstWhere('id', $route['vehicle_id']);
                $improvements = 0;
                do {
                    $improved = false;
                    for ($i = 0; $i < count($route['stops']) - 1; $i++) {
                        for ($j = $i + 1; $j < count($route['stops']); $j++) {
                            if ($clock() >= $deadline) {
                                $budget = true;
                                break 3;
                            }
                            $candidate = [...array_slice($route['stops'], 0, $i), ...array_reverse(array_slice($route['stops'], $i, $j - $i + 1)), ...array_slice($route['stops'], $j + 1)];
                            $cost = $this->cost($s, $candidate);
                            // Directed matrices require the complete reversed tour, including depot return.
                            if ($cost && $cost['km'] < $route['km'] - 1e-9 && $this->feasible($s, $candidate, $vehicle)) {
                                $route = array_replace($route, ['stops' => $candidate], $cost);
                                $improved = true;
                                $iterations++;
                                $improvements++;
                                break 2;
                            }
                        }
                    }
                } while ($improved && $improvements < 100);
                $iterationLimit = $iterationLimit || ($improved && $improvements === 100);
            }
            unset($route);
        }
        $served = array_merge([], ...array_column($routes, 'stops'));
        sort($served);
        ksort($remaining);
        $unserved = [];
        foreach ($remaining as $job) {
            $matching = array_filter($vehicles, fn ($v) => $v['collector_id'] === $job['collector_id']);
            $reason = ! $matching ? 'collector_not_in_fleet' : 'fleet_capacity_or_duration_exhausted';
            if ($matching && ! $this->cost($s, [$job['id']])) {
                $reason = 'unreachable_depot_leg';
            } elseif ($matching && ! array_filter($matching, fn ($v) => $job['kg'] <= $v['capacity_kg'])) {
                $reason = 'over_capacity';
            } elseif ($matching && ! array_filter($matching, fn ($v) => $this->feasible($s, [$job['id']], $v))) {
                $reason = 'tour_duration_exceeded';
            }
            $unserved[] = ['id' => $job['id'], 'reason' => $budget ? 'computation_budget_exhausted' : $reason];
        }

        return ['routes' => $routes, 'served' => $served, 'unserved' => $unserved, 'km' => array_sum(array_column($routes, 'km')), 'seconds' => array_sum(array_column($routes, 'seconds')), 'vehicles_used' => count($routes), 'budget_exhausted' => $budget, 'improving_iterations' => $iterations, 'iteration_limit_reached' => $iterationLimit, 'constraint_violations' => 0];
    }

    public function cost(array $s, array $stops): ?array
    {
        $jobs = array_column($s['jobs'], null, 'id');
        $km = $seconds = $kg = 0;
        $last = 'depot';
        foreach ([...$stops, 'depot'] as $id) {
            $edge = $s['matrix'][$last][$id] ?? null;
            if (! $edge || ! is_numeric($edge['km']) || ! is_numeric($edge['seconds']) || ! is_finite((float) $edge['km']) || ! is_finite((float) $edge['seconds']) || $edge['km'] < 0 || $edge['seconds'] < 0) {
                return null;
            }
            $km += $edge['km'];
            $seconds += $edge['seconds'] + ($jobs[$id]['service_seconds'] ?? 0);
            $kg += $jobs[$id]['kg'] ?? 0;
            $last = $id;
        }

        return ['km' => $km, 'seconds' => $seconds, 'kg' => $kg];
    }

    private function feasible(array $s, array $stops, array $vehicle): bool
    {
        $cost = $this->cost($s, $stops);

        return $cost && $cost['kg'] <= $vehicle['capacity_kg'] + 1e-9 && $cost['seconds'] <= $vehicle['max_duration_seconds'] + 1e-9;
    }

    public function validate(array $s, array $result): void
    {
        if (abs($result['km'] - array_sum(array_column($result['routes'], 'km'))) > 1e-6 || abs($result['seconds'] - array_sum(array_column($result['routes'], 'seconds'))) > 1e-6 || $result['vehicles_used'] !== count($result['routes'])) {
            throw new InvalidArgumentException('Invalid aggregate metrics.');
        }
        $seen = $vehicleIds = $collectors = [];
        $jobs = array_column($s['jobs'], null, 'id');
        foreach ($result['routes'] as $route) {
            $vehicle = collect($s['vehicles'])->firstWhere('id', $route['vehicle_id']);
            if (! $vehicle || ! $route['stops'] || isset($vehicleIds[$vehicle['id']]) || isset($collectors[$vehicle['collector_id']]) || $route['collector_id'] !== $vehicle['collector_id'] || ! $this->feasible($s, $route['stops'], $vehicle)) {
                throw new InvalidArgumentException('Invalid route constraints.');
            }
            $vehicleIds[$vehicle['id']] = $collectors[$vehicle['collector_id']] = true;
            foreach ($route['stops'] as $id) {
                if (isset($seen[$id]) || ! isset($jobs[$id]) || $jobs[$id]['collector_id'] !== $vehicle['collector_id']) {
                    throw new InvalidArgumentException('Invalid route coverage or Collector.');
                }
                $seen[$id] = true;
            }
            foreach ($this->cost($s, $route['stops']) as $key => $value) {
                if (abs($route[$key] - $value) > 1e-6) {
                    throw new InvalidArgumentException('Invalid route metrics.');
                }
            }
        }
        $served = array_keys($seen);
        sort($served);
        if ($served !== $result['served'] || count($served) + count($result['unserved']) !== count($jobs)) {
            throw new InvalidArgumentException('Invalid result coverage.');
        }
        foreach ($result['unserved'] as $row) {
            if (! isset($jobs[$row['id']]) || isset($seen[$row['id']]) || empty($row['reason'])) {
                throw new InvalidArgumentException('Invalid unserved accounting.');
            }
            $seen[$row['id']] = true;
        }
    }

    private function inputs(array $s): void
    {
        if (count($s['jobs']) > 50 || count($s['vehicles']) > 3 || count(array_unique(array_column($s['jobs'], 'id'))) !== count($s['jobs']) || count(array_unique(array_column($s['vehicles'], 'id'))) !== count($s['vehicles']) || count(array_unique(array_column($s['vehicles'], 'collector_id'))) !== count($s['vehicles'])) {
            throw new InvalidArgumentException('Routing bounds exceeded.');
        }
        foreach ($s['vehicles'] as $vehicle) {
            if ($vehicle['capacity_kg'] <= 0 || $vehicle['max_duration_seconds'] <= 0) {
                throw new InvalidArgumentException('Invalid vehicle capacity or duration.');
            }
        }
        foreach ($s['jobs'] as $job) {
            if ($job['kg'] <= 0 || $job['service_seconds'] < 0) {
                throw new InvalidArgumentException('Routing mass/service input is invalid.');
            }
        }
    }
}
