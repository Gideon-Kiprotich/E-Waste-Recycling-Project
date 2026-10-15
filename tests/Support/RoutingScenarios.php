<?php

namespace Tests\Support;

use App\Support\Routing\Planner;

class RoutingScenarios
{
    public static function all(): array
    {
        $scenarios = [];
        foreach (['uniform', 'clustered', 'tight_capacity', 'tight_duration', 'mixed_stress'] as $family) {
            foreach ([10, 25, 50] as $size) {
                foreach ([1701, 2903] as $seed) {
                    $s = ['name' => $family.'-'.$size.'-'.$seed, 'family' => $family, 'seed' => $seed, 'submitted' => $size, 'study_polygon' => ['type' => 'Polygon', 'coordinates' => [[[36.75, -1.35], [36.90, -1.35], [36.90, -1.20], [36.75, -1.20], [36.75, -1.35]]]], 'source' => 'HAVERSINE-SIMULATION-1.0', 'profile' => 'synthetic constant speed; explicit unreachable stress edges only', 'speed_kmh' => 20, 'depot' => ['latitude' => -1.28, 'longitude' => 36.82], 'jobs' => [], 'excluded' => [], 'vehicles' => []];
                    foreach ([100, 200, 300] as $i => $capacity) {
                        $s['vehicles'][] = ['id' => 'vehicle-'.$i, 'collector_id' => 'collector-'.$i, 'capacity_kg' => $family === 'tight_capacity' ? 20 + 10 * $i : $capacity, 'max_duration_seconds' => $family === 'tight_duration' ? 1000 : 21600];
                    }
                    for ($i = 0; $i < $size; $i++) {
                        $id = sprintf('job-%03d', $i);
                        $fraction = fn ($key) => hexdec(substr(hash('sha256', $seed.':'.$size.':'.$i.':'.$key), 0, 8)) / 4294967295;
                        $lat = $family === 'clustered' ? -1.285 + 0.01 * $fraction('lat') : -1.34 + 0.13 * $fraction('lat');
                        $lon = $family === 'clustered' ? 36.815 + 0.01 * $fraction('lon') : 36.76 + 0.13 * $fraction('lon');
                        if ($family === 'mixed_stress' && $i % 11 === 0) {
                            $s['excluded'][] = ['id' => $id, 'reason' => 'unknown_routing_mass'];

                            continue;
                        }
                        $s['jobs'][] = ['id' => $id, 'collector_id' => 'collector-'.($i % 3), 'submitted_at' => sprintf('2026-01-%02dT%02d:00:00Z', 1 + intdiv($i, 24), $i % 24), 'latitude' => $lat, 'longitude' => $lon, 'kg' => $family === 'mixed_stress' && $i % 7 === 0 ? 500 : 5 + 20 * $fraction('mass'), 'service_seconds' => 600];
                    }
                    $s['matrix'] = (new Planner)->matrix($s['jobs'], $s['depot'], 20);
                    if ($family === 'mixed_stress') {
                        foreach ($s['jobs'] as $j) {
                            if ((int) substr($j['id'], 4) % 9 === 0) {
                                $s['matrix']['depot'][$j['id']] = null;
                                $s['matrix'][$j['id']]['depot'] = null;
                            }
                        }
                    }
                    $scenarios[] = $s;
                }
            }
        }

        return $scenarios;
    }

    public static function variations(array $s): array
    {
        $out = [];
        foreach (['speed' => [10, 30], 'service_minutes' => [5, 15], 'mass_multiplier' => [0.75, 1.25], 'capacity_multiplier' => [0.75, 1.25]] as $parameter => $values) {
            foreach ($values as $value) {
                $copy = $s;
                if ($parameter === 'speed') {
                    foreach ($copy['matrix'] as &$edges) {
                        foreach ($edges as &$edge) {
                            if ($edge !== null) {
                                $edge['seconds'] *= 20 / $value;
                            }
                        }
                    }unset($edge,$edges);
                    $copy['speed_kmh'] = $value;
                } elseif ($parameter === 'service_minutes') {
                    foreach ($copy['jobs'] as &$j) {
                        $j['service_seconds'] = $value * 60;
                    }unset($j);
                } elseif ($parameter === 'mass_multiplier') {
                    foreach ($copy['jobs'] as &$j) {
                        $j['kg'] *= $value;
                    }unset($j);
                } else {
                    foreach ($copy['vehicles'] as &$v) {
                        $v['capacity_kg'] *= $value;
                    }unset($v);
                }
                $out[] = ['parameter' => $parameter, 'value' => $value, 'snapshot' => $copy];
            }
        }

        return $out;
    }

    public static function export(string $name, array $records): void
    {
        $directory = getenv('ROUTING_EVALUATION_OUTPUT');
        if (! $directory) {
            return;
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create evaluation output.');
        }
        file_put_contents($directory.'/'.$name, implode("\n", array_map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $records))."\n");
    }
}
