<?php

namespace App\Support\Assessments;

class Evaluator
{
    public function evaluate(array $schema, array $answers, array $naReasons, string $faultReason, bool $hasEvidence): array
    {
        $questions = $schema['questions'];
        $decisive = array_keys(array_filter($questions, fn ($q) => $q['decisive']));
        $missing = [];
        foreach ($questions as $code => $q) {
            $value = $answers[$code] ?? null;
            if (! in_array($value, $q['values'], true) || ($value === 'NOT_APPLICABLE' && trim($naReasons[$code] ?? '') === '') || ($q['decisive'] && $value === 'UNKNOWN')) {
                $missing[] = $code;
            }
        }
        $unsupported = ! $questions;
        $boundaryUnknown = [];
        $hazard = false;
        foreach (['charging_risk', 'battery_risk', 'thermal_issue'] as $code) {
            $hazard = $hazard || ($answers[$code] ?? null) === 'YES';
            if (($answers[$code] ?? null) === 'UNKNOWN') {
                $boundaryUnknown[] = $code;
            }
        }
        foreach (['media_identified' => 'NO', 'fan_voltage' => 'OUTSIDE', 'fan_size' => 'OUTSIDE', 'fan_application' => 'OTHER', 'fan_electronics' => 'ADDITIONAL'] as $code => $outside) {
            $unsupported = $unsupported || ($answers[$code] ?? null) === $outside;
            if (($answers[$code] ?? null) === 'UNKNOWN') {
                $boundaryUnknown[] = $code;
            }
        }
        $failed = array_filter($decisive, fn ($code) => ($answers[$code] ?? null) === 'FAIL');
        $allPass = $decisive && count(array_filter($decisive, fn ($code) => ($answers[$code] ?? null) === 'PASS')) === count($decisive);
        $repair = $answers['repairability'] ?? null;
        $conflicts = $allPass && $repair === 'NON_REPAIRABLE' ? ['repairability'] : [];
        $supportedFailure = trim($faultReason) !== '' && $hasEvidence;
        if ($failed && in_array($repair, ['REPAIRABLE', 'NON_REPAIRABLE'], true) && ! $supportedFailure) {
            $missing[] = 'fault_evidence';
        }
        $missing = array_values(array_unique([...$missing, ...$boundaryUnknown]));
        $conditions = [
            'R01' => $unsupported || $hazard || ($answers['safety_concern'] ?? null) === 'YES',
            'R02' => ($answers['safety_concern'] ?? null) === 'UNKNOWN',
            'R03' => (bool) ($missing || $conflicts),
            'R04' => $failed && $repair === 'NON_REPAIRABLE' && $supportedFailure,
            'R05' => $failed && $repair === 'REPAIRABLE' && $supportedFailure,
            'R06' => $failed && $repair === 'UNDETERMINED',
            'R07' => (bool) $allPass,
        ];
        $rule = $this->firstMatch($conditions);
        $data = $answers['data_status'] ?? (isset($questions['media_identified']) ? 'YES' : (($unsupported || $boundaryUnknown) ? 'UNKNOWN' : 'NO'));

        return ['rule' => $rule, 'recommendation' => match ($rule) {
            'R04' => 'recycle', 'R05', 'R07' => 'refurbish', default => 'hold'
        }, 'explanation' => $schema['rules'][$rule], 'priority' => array_keys($schema['rules']), 'missing' => $missing, 'conflicts' => $conflicts, 'decisive_answers' => array_intersect_key($answers, array_flip($decisive)), 'hard_hold' => $conditions['R01'] || $conditions['R02'] || (bool) $boundaryUnknown, 'data_status' => $data, 'data_gate' => $data === 'NO' ? 'No clearance is established by this assessment.' : 'Unresolved: assessment does not sanitize or authorize release.'];
    }

    public function firstMatch(array $conditions): string
    {
        foreach (['R01', 'R02', 'R03', 'R04', 'R05', 'R06', 'R07'] as $code) {
            if ($conditions[$code] ?? false) {
                return $code;
            }
        }

        return 'R08';
    }
}
