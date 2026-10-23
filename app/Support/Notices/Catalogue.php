<?php

namespace App\Support\Notices;

use App\Support\Assignments\History;

class Catalogue
{
    public const LABELS = [
        'item_registered' => 'Item registered — self-report retained', 'self_report_revised' => 'Self-report revised',
        'request_submitted' => 'Collection request submitted', 'request_items_cancelled' => 'Request items cancelled', 'request_date_preference_changed' => 'Date preference changed — not booked', 'request_eligibility_revalidated' => 'Request eligibility reviewed', 'request_review_recorded' => 'Request review recorded',
        'route_plan_approved' => 'Proposed route approved — not a booked appointment', 'route_plan_withdrawn' => 'Proposed route withdrawn',
        'first_receipt' => 'First physical receipt acknowledged', 'physical_visit_recorded' => 'Physical visit outcomes recorded', 'handover_accepted' => 'Physical handover acknowledged',
        'inspection_recorded' => 'Inspection recorded', 'pathway_decided' => 'Human pathway decision recorded',
        'media_identified' => 'Media identified at facility — no new consumer collection',
        'sanitization_attempt' => 'Media treatment attempt recorded', 'sanitization_verification' => 'Media verification recorded', 'sanitization_validation' => 'Media validation recorded', 'sanitization_revalidation' => 'Media revalidation recorded',
        'refurbishment_work' => 'Refurbishment work recorded', 'refurbishment_work_applicability' => 'Work applicability recorded', 'refurbishment_test' => 'Functional test recorded', 'refurbishment_fan_na' => 'Fan work applicability recorded', 'refurbishment_authorize' => 'Owner return authorized — receipt pending', 'refurbishment_withdraw' => 'Owner return authorization withdrawn', 'refurbishment_exception' => 'Owner return exception needs review', 'refurbishment_acknowledge' => 'Owner acknowledged reuse return', 'reuse_returned' => 'Reuse return physically acknowledged',
        'batch_commit' => 'Dismantling batch committed', 'dismantled_in_batch' => 'Dismantled into processing batch', 'media_detached_in_batch' => 'Media detached in processing batch', 'batch_output' => 'Batch output observed', 'batch_measurement' => 'Batch measurement appended', 'batch_accounted' => 'Batch output accounting recorded', 'batch_discrepancy' => 'Unexpected batch media needs review',
        'downstream_reserve' => 'Stock reserved for named recipient — no dispatch or receipt', 'downstream_dispatch' => 'Actual output dispatch recorded — completion pending', 'downstream_accept' => 'Destination quantity acknowledged', 'downstream_refuse' => 'Destination refusal recorded', 'downstream_shortage' => 'Destination shortage recorded', 'downstream_dispute' => 'Quantity dispute recorded', 'downstream_complete' => 'Downstream completion evidence recorded', 'downstream_source_loss' => 'Source-stock loss reported', 'downstream_downstream_loss' => 'Downstream loss reported', 'downstream_measurement_dispute' => 'Measurement or later evidence dispute recorded', 'downstream_close' => 'Batch accounting closure recorded — current status is separate', 'downstream_recipient_accepted' => 'Recipient responsibility accepted — no physical receipt', 'downstream_recipient_cancelled' => 'Recipient responsibility proposal cancelled',
    ];

    public static function label(string $type): string
    {
        if (isset(self::LABELS[$type])) {
            return self::LABELS[$type];
        }
        if (str_starts_with($type, 'assignment_')) {
            return History::LABELS[substr($type, 11)] ?? 'Assignment update recorded';
        }
        if (str_starts_with($type, 'restriction_')) {
            return 'Restriction review update — original facts retained; current obligations apply';
        }
        if (str_starts_with($type, 'handover_')) {
            return 'Custody handover '.str_replace('_', ' ', substr($type, 9));
        }

        return 'Recorded milestone — authorized details required';
    }
}
