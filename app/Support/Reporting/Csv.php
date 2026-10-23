<?php

namespace App\Support\Reporting;

use App\Support\NairobiTime;
use Carbon\CarbonImmutable;

class Csv
{
    public const COMMON = ['report_kind', 'mode', 'definition', 'revision', 'generated_utc', 'generated_nairobi', 'timezone', 'date_basis', 'from', 'to', 'category_filter', 'subtype_filter', 'facility_filter', 'output_type_filter', 'material_filter', 'row_kind', 'metric', 'value'];

    public const FIELDS = ['id', 'public_code', 'batch_id', 'shipment_id', 'intake_class', 'category', 'subtype', 'observed_utc', 'observed_nairobi', 'facility', 'status', 'recorded_status', 'closure_reference', 'closure_observed_utc', 'closure_observed_nairobi', 'latest_review_reference', 'latest_review_recorded_utc', 'latest_review_recorded_nairobi', 'cohort_reference', 'production_reference', 'original_reference', 'effective_reference', 'review_references', 'blockers_non_additive', 'estimated_kg', 'historical_acknowledged', 'qualified', 'original_input_kg', 'input_kg', 'original_produced_kg', 'produced_kg', 'unknown_outputs', 'measured_outputs', 'eligible_outputs', 'variance_kg', 'tolerance_kg', 'historical_closed', 'output_type', 'material', 'dispatched_kg', 'source_loss_kg', 'reserved_kg', 'accounted_kg', 'reservable_kg', 'accepted_kg', 'refused_kg', 'shortage_kg', 'disputed_kg', 'transit_loss_kg', 'unreported_kg', 'recovered_kg', 'disposed_kg', 'controlled_storage_kg', 'accepted_loss_kg', 'pending_kg', 'original_recovered_kg', 'qualified_recovered_kg', 'disputed_recovered_kg', 'original_disposed_kg', 'qualified_disposed_kg', 'disputed_disposed_kg', 'original_controlled_storage_kg', 'qualified_controlled_storage_kg', 'disputed_controlled_storage_kg', 'total_lost_kg', 'confirmed_source_loss_kg', 'reported_unconfirmed_loss_kg', 'quantity_kg', 'outcome', 'route_id', 'position', 'item_codes', 'item_count', 'preferred_dates_not_booked', 'contact', 'address', 'latitude', 'longitude', 'destination'];

    public static function text(mixed $value): string
    {
        $text = (string) ($value ?? '');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        if (preg_match('/\A[\s\p{Z}]*[=+\-@\t\r\n]/u', $text) || preg_match('/\A[\t\r\n]/', $text)) {
            $text = "'".$text;
        }

        return $text;
    }

    public function encode(array $snapshot, float $started): array
    {
        $manifest = $snapshot['filters']['kind'] === 'manifest';
        $maxRows = $manifest ? 50 : 10000;
        $maxBytes = $manifest ? 2 * 1024 * 1024 : 10 * 1024 * 1024;
        if (count($snapshot['rows']) > $maxRows) {
            throw new ExportLimit;
        }
        $stream = fopen('php://temp', 'w+');
        $fields = array_merge(self::COMMON, self::FIELDS);
        fputcsv($stream, $fields, ',', '"', '', "\r\n");
        $filters = $snapshot['filters'];
        $metadata = ['report_kind' => $filters['kind'], 'mode' => 'current_effective', 'definition' => $snapshot['definition'], 'revision' => $snapshot['revision'], 'generated_utc' => $snapshot['generated_at'], 'generated_nairobi' => NairobiTime::display($snapshot['generated_at']), 'timezone' => 'Africa/Nairobi', 'date_basis' => match ($filters['kind']) {
            'collections' => 'Original first receipt observed date', 'facility' => 'First processing facility admission observed date', 'batches' => 'Original batch commit observed date', 'incoming' => 'Shipment creation observed date; non-additive slices', default => 'Live current work; dates do not apply'
        }, 'from' => $filters['from'], 'to' => $filters['to'], 'category_filter' => $filters['category'], 'subtype_filter' => $filters['subtype'], 'facility_filter' => $filters['facility'], 'output_type_filter' => $filters['output_type'] ?? '', 'material_filter' => $filters['material'] ?? ''];
        $rows = [];
        foreach ($snapshot['summary'] as $metric => $value) {
            $rows[] = ['row_kind' => 'report_summary_non_additive', 'metric' => $metric, 'value' => $value];
        }
        array_push($rows, ...$snapshot['rows']);
        if (! $manifest && count($rows) > $maxRows) {
            fclose($stream);
            throw new ExportLimit;
        }
        foreach ($rows as $row) {
            foreach (['observed_at' => 'observed', 'closure_observed_at' => 'closure_observed', 'latest_review_recorded_at' => 'latest_review_recorded'] as $field => $prefix) {
                if (isset($row[$field])) {
                    $row[$prefix.'_utc'] = CarbonImmutable::parse($row[$field])->utc()->toIso8601String();
                    $row[$prefix.'_nairobi'] = NairobiTime::display($row[$field]);
                }
            }
            $values = $metadata + $row;
            $cells = [];
            foreach ($fields as $field) {
                $value = $values[$field] ?? '';
                // Only schema-defined numeric fields may retain a leading minus.
                $numeric = str_ends_with($field, '_kg') || in_array($field, ['position', 'item_count', 'qualified', 'historical_acknowledged', 'historical_closed', 'unknown_outputs', 'measured_outputs', 'eligible_outputs'], true);
                $cells[] = $numeric && preg_match('/\A-?\d+(?:\.\d+)?\z/', (string) $value) ? (string) $value : self::text($value);
            }
            fputcsv($stream, $cells, ',', '"', '', "\r\n");
            if (ftell($stream) > $maxBytes || microtime(true) - $started > 15) {
                fclose($stream);
                throw new ExportLimit;
            }
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return [$bytes, count($snapshot['rows'])];
    }
}
