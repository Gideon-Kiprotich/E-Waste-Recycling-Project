<?php

namespace Tests\Unit;

use App\Support\Assessments\Evaluator;
use App\Support\Assessments\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssessmentEvaluationTest extends TestCase
{
    public static function cases(): array
    {
        $ledger = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/evaluation/phase-9-cases.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($c) => ['id' => $c['id'], 'args' => [$c]], $ledger['cases']), 'args', 'id');
    }

    #[DataProvider('cases')]
    public function test_preregistered_rule_conformance_and_repeatability(array $case): void
    {
        $schema = Schemas::definition($case['category'], $case['subtype']);
        $evaluator = new Evaluator;
        $a = $evaluator->evaluate($schema, $case['answers'], $case['na_reasons'], $case['fault_reason'], true);
        $b = $evaluator->evaluate($schema, $case['answers'], $case['na_reasons'], $case['fault_reason'], true);
        $this->assertSame($case['expected_rule'], $a['rule']);
        $this->assertSame($case['expected_recommendation'], $a['recommendation']);
        $this->assertSame($a, $b);
        $this->assertSame($case['schema_version'], $schema['schema_version']);
        $this->assertNotEmpty($a['explanation']);
        $this->assertNotEmpty($a['decisive_answers']);
        $this->assertSame(['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07', 'R08'], $a['priority']);
        if ($case['cohort'] === 'whole_device') {
            $this->assertSame('UNKNOWN', $a['data_status']);
            $this->assertStringContainsString('Unresolved', $a['data_gate']);
        } elseif ($case['subtype'] !== 'ict_cooling_fan') {
            $this->assertSame('YES', $a['data_status']);
        }
    }

    public function test_predeclared_rule_boundaries_and_defensive_fallback(): void
    {
        $case = self::cases()['laptop-healthy'][0];
        $schema = Schemas::definition('laptop', null);
        $e = new Evaluator;
        foreach ([['safety_concern' => 'UNKNOWN'], ['power' => 'FAIL', 'repairability' => 'UNDETERMINED'], ['power' => 'NOT_APPLICABLE'], ['power' => 'UNKNOWN', 'safety_concern' => 'YES']] as $i => $changes) {
            $r = $e->evaluate($schema, array_replace($case['answers'], $changes), [], 'Recorded fault', true);
            $this->assertSame(['R02', 'R06', 'R03', 'R01'][$i], $r['rule']);
        }
        $answers = $case['answers'];
        unset($answers['power']);
        $this->assertSame('R03', $e->evaluate($schema, $answers, [], '', false)['rule']);
        $answers['power'] = 'FAIL';
        $this->assertSame('R03', $e->evaluate($schema, $answers, [], '', false)['rule']);
        $schema['questions'] = [];
        $this->assertSame('R01', $e->evaluate($schema, [], [], '', false)['rule']);
        $this->assertSame('R08', $e->firstMatch([]));
        foreach (['fan_voltage' => 'UNKNOWN', 'fan_size' => 'OUTSIDE', 'fan_electronics' => 'ADDITIONAL', 'fan_application' => 'UNKNOWN'] as $code => $value) {
            $case = self::cases()['ict_cooling_fan-healthy'][0];
            $r = $e->evaluate(Schemas::definition('standalone_part', 'ict_cooling_fan'), array_replace($case['answers'], [$code => $value]), [], 'Observed', true);
            $this->assertSame($value === 'UNKNOWN' ? 'R03' : 'R01', $r['rule']);
            $this->assertTrue($r['hard_hold']);
            $this->assertSame('UNKNOWN', $r['data_status']);
        }
        foreach (['NO' => 'R01', 'UNKNOWN' => 'R03'] as $v => $expected) {
            $case = self::cases()['hdd-healthy'][0];
            $r = $e->evaluate(Schemas::definition('standalone_part', 'hdd'), array_replace($case['answers'], ['media_identified' => $v]), [], 'Observed', true);
            $this->assertSame($expected, $r['rule']);
            $this->assertTrue($r['hard_hold']);
            $this->assertSame('YES', $r['data_status']);
        }
    }

    public function test_explicit_secondary_hazards_cannot_be_contradicted_by_shared_safe_answer(): void
    {
        foreach (['laptop-healthy' => 'charging_risk', 'phone-healthy' => 'battery_risk', 'ssd-healthy' => 'thermal_issue'] as $fixture => $code) {
            $c = self::cases()[$fixture][0];
            foreach (['YES' => 'R01', 'UNKNOWN' => 'R03'] as $value => $expected) {
                $r = (new Evaluator)->evaluate(Schemas::definition($c['category'], $c['subtype']), array_replace($c['answers'], [$code => $value]), $c['na_reasons'], $c['fault_reason'], true);
                $this->assertSame($expected, $r['rule']);
                $this->assertSame('hold', $r['recommendation']);
                $this->assertTrue($r['hard_hold']);
            }
        }
    }
}
