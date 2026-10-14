<?php

namespace App\Support\Collection;

class ReviewFeedback
{
    public static function reasons(array $codes): array
    {
        return array_map(function (string $code): array {
            if (isset(Eligibility::REASONS[$code])) {
                return ['kind' => 'Safety / scope restriction', 'message' => Eligibility::REASONS[$code].' Keep an explained Hold until the facts are resolved through an authorized process. No safety override is available.', 'directory' => false];
            }
            [$kind, $message, $directory] = match ($code) {
                'no_compatible_receiving_place' => ['Missing receiving setup', 'No compatible receiving place. Use Operators and places to approve a Processor operator or Collector and processor, then an active, project-reviewed receiving facility or collection point accepting every outstanding category and subtype. Collector-only places and depots cannot be receiving destinations.', true],
                'no_reviewed_collector' => ['Missing collecting setup', 'No eligible collecting operator. Use Operators and places to approve a Collector operator or Collector and processor with an active, project-reviewed place accepting every outstanding category and subtype. Operator approval alone does not add capabilities.', true],
                'date_preference_elapsed' => ['Request eligibility', 'The preferred date has passed. Ask the Consumer to open My requests, change the outstanding items to today or a future preferred date, then record a fresh Ready review. A date preference is not a booking.', false],
                'location_or_compatibility_changed' => ['Location / compatibility', 'The selected service area or drop-off point is unavailable, out of area or incompatible. Check the current area/point and item capabilities; a visible map pin does not establish service eligibility.', true],
                'owner_unavailable' => ['Account eligibility', 'The Consumer account or membership is unavailable. Keep this request on Hold until account access is resolved through an authorized process.', false],
                'ownership_changed' => ['Request eligibility', 'An outstanding item no longer belongs to this Consumer. Resolve the ownership discrepancy before a fresh review; allocation cannot change ownership.', false],
                'no_outstanding_items' => ['Request eligibility', 'No outstanding reserved items remain. There is no pickup job to offer.', false],
                'item_unavailable', 'item_ineligible' => ['Request eligibility', 'An outstanding item is unavailable or no longer eligible for collection. Inspect its current status in Request review before recording another decision.', false],
                default => ['Request eligibility', 'Current request facts require review before allocation. Open Request review and resolve the recorded restriction.', false],
            };

            return compact('kind', 'message', 'directory');
        }, array_values(array_unique($codes)));
    }

    public static function readyError(array $codes): string
    {
        return 'Ready review blocked. '.implode(' ', array_column(self::reasons($codes), 'message'));
    }
}
