<?php

namespace Tests\Unit;

use App\Support\Routing\Planner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RoutePlannerTest extends TestCase
{
    private function fixture(): array
    {
        return ['jobs' => [['id' => 'a', 'collector_id' => 'c', 'submitted_at' => '2026-01-01', 'kg' => 2, 'service_seconds' => 10], ['id' => 'b', 'collector_id' => 'c', 'submitted_at' => '2026-01-02', 'kg' => 3, 'service_seconds' => 20]], 'vehicles' => [['id' => 'v', 'collector_id' => 'c', 'capacity_kg' => 5, 'max_duration_seconds' => 90]], 'matrix' => ['depot' => ['depot' => ['km' => 0, 'seconds' => 0], 'a' => ['km' => 1, 'seconds' => 10], 'b' => ['km' => 3, 'seconds' => 30]], 'a' => ['depot' => ['km' => 1, 'seconds' => 10], 'a' => ['km' => 0, 'seconds' => 0], 'b' => ['km' => 2, 'seconds' => 20]], 'b' => ['depot' => ['km' => 3, 'seconds' => 30], 'a' => ['km' => 2, 'seconds' => 20], 'b' => ['km' => 0, 'seconds' => 0]]]];
    }

    public function test_hand_calculated_capacity_service_and_return_are_included(): void
    {
        $r = (new Planner)->compare($this->fixture());
        foreach (['baseline', 'proposed'] as $m) {
            $this->assertSame(['a', 'b'], $r[$m]['served']);
            $this->assertEquals(6, $r[$m]['km']);
            $this->assertEquals(90, $r[$m]['seconds']);
            $this->assertEquals(5, $r[$m]['routes'][0]['kg']);
        }
    }

    public function test_capacity_return_time_unreachable_and_collector_mismatch_exclude_with_reasons(): void
    {
        foreach (['capacity', 'return', 'unreachable', 'collector'] as $case) {
            $s = $this->fixture();
            $s['jobs'] = [$s['jobs'][1]];
            if ($case === 'capacity') {
                $s['vehicles'][0]['capacity_kg'] = 2;
            }
            if ($case === 'return') {
                $s['vehicles'][0]['max_duration_seconds'] = 79;
            }
            if ($case === 'unreachable') {
                $s['matrix']['b']['depot'] = null;
            }
            if ($case === 'collector') {
                $s['jobs'][0]['collector_id'] = 'other';
            }
            $r = (new Planner)->compare($s);
            $this->assertSame([], $r['proposed']['served']);
            $this->assertSame(match ($case) {
                'capacity' => 'over_capacity','return' => 'tour_duration_exceeded','unreachable' => 'unreachable_depot_leg','collector' => 'collector_not_in_fleet'
            }, $r['proposed']['unserved'][0]['reason']);
        }
    }

    public function test_asymmetric_reversals_use_complete_directed_cost_and_remain_feasible(): void
    {
        $s = $this->fixture();
        $s['vehicles'][0]['max_duration_seconds'] = 10000;
        $s['matrix']['b']['a'] = ['km' => 100, 'seconds' => 1000];
        $planner = new Planner;
        $r = $planner->compare($s);
        $this->assertEquals(104, $planner->cost($s, ['b', 'a'])['km']);
        $this->assertEquals(6, $r['proposed']['km']);
        $this->assertSame(['a', 'b'], $r['proposed']['routes'][0]['stops']);
    }

    public function test_stable_ties_and_determinism_ignore_input_order(): void
    {
        $s = $this->fixture();
        $s['jobs'][1]['submitted_at'] = $s['jobs'][0]['submitted_at'];
        $s['matrix']['depot']['b'] = $s['matrix']['depot']['a'];
        $s['matrix']['b']['depot'] = $s['matrix']['a']['depot'];
        $a = (new Planner)->compare($s);
        $s['jobs'] = array_reverse($s['jobs']);
        $b = (new Planner)->compare($s);
        foreach (['baseline', 'proposed'] as $m) {
            $this->assertSame($a[$m], $b[$m]);
        }
        $this->assertSame('a', $a['proposed']['routes'][0]['stops'][0]);
    }

    public function test_different_coverage_and_zero_baseline_never_produce_a_savings_percentage(): void
    {
        $s = $this->fixture();
        $s['vehicles'][0]['capacity_kg'] = 3;
        $s['jobs'][0]['kg'] = 3;
        $s['matrix']['depot']['a'] = ['km' => 4, 'seconds' => 10];
        $s['matrix']['depot']['b'] = ['km' => 1, 'seconds' => 10];
        $r = (new Planner)->compare($s);
        $this->assertFalse($r['comparison']['same_coverage']);
        $this->assertNull($r['comparison']['improvement_percent']);
        $s['jobs'] = [];
        $r = (new Planner)->compare($s);
        $this->assertNull($r['comparison']['improvement_percent']);
    }

    public function test_expired_budget_retains_valid_partial_results_and_explicit_accounting(): void
    {
        $s = $this->fixture();
        $r = (new Planner)->compare($s, 0, fn () => 1);
        foreach (['baseline', 'proposed'] as $m) {
            $this->assertTrue($r[$m]['budget_exhausted']);
            $this->assertCount(2, $r[$m]['unserved']);
            $this->assertSame([], $r[$m]['served']);
        }
    }

    public function test_geometric_distance_has_known_equatorial_reference_and_zero_diagonal(): void
    {
        $m = (new Planner)->matrix([['id' => 'a', 'latitude' => 0, 'longitude' => 1]], ['latitude' => 0, 'longitude' => 0], 20);
        $this->assertEqualsWithDelta(111.19508, $m['depot']['a']['km'], 0.0001);
        $this->assertEqualsWithDelta(20015.1144, $m['depot']['a']['seconds'], 0.01);
        $this->assertEquals(0, $m['a']['a']['km']);
    }

    public function test_independent_validation_rejects_forged_metrics_and_duplicate_coverage(): void
    {
        $s = $this->fixture();
        $p = new Planner;
        $r = $p->compare($s)['proposed'];
        $r['routes'][0]['seconds'] = 0;
        $this->expectException(InvalidArgumentException::class);
        $p->validate($s, $r);
    }

    public function test_tiny_directed_tours_match_exhaustive_hand_calculated_costs(): void
    {
        $s = $this->fixture();
        $s['vehicles'][0]['capacity_kg'] = 100;
        $s['vehicles'][0]['max_duration_seconds'] = 10000;
        $s['jobs'][] = ['id' => 'c', 'collector_id' => 'c', 'submitted_at' => '2026-01-03', 'kg' => 1, 'service_seconds' => 0];
        $distances = ['depot' => ['depot' => 0, 'a' => 2, 'b' => 5, 'c' => 7], 'a' => ['depot' => 3, 'a' => 0, 'b' => 2, 'c' => 9], 'b' => ['depot' => 6, 'a' => 7, 'b' => 0, 'c' => 2], 'c' => ['depot' => 3, 'a' => 1, 'b' => 8, 'c' => 0]];
        foreach ($distances as $a => $edges) {
            foreach ($edges as $b => $km) {
                $s['matrix'][$a][$b] = ['km' => $km, 'seconds' => $km * 10];
            }
        }
        $tours = [['a', 'b', 'c'], ['a', 'c', 'b'], ['b', 'a', 'c'], ['b', 'c', 'a'], ['c', 'a', 'b'], ['c', 'b', 'a']];
        $expected = [9, 25, 24, 11, 16, 25];
        $planner = new Planner;
        foreach ($tours as $i => $tour) {
            $this->assertEquals($expected[$i], $planner->cost($s, $tour)['km']);
        }
        $result = $planner->compare($s);
        $this->assertEquals(min($expected), $result['proposed']['km']);
    }
}
