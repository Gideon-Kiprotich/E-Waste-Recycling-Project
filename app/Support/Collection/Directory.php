<?php

namespace App\Support\Collection;

use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Directory
{
    public function points(): Collection
    {
        return DB::table('places')->join('organizations', 'organizations.id', '=', 'places.organization_id')
            ->where('places.active', true)->where('places.review_status', 'approved')->where('organizations.review_status', 'approved')
            ->select('places.*')->orderBy('places.name')->get();
    }

    public function compatible(object $point, Collection $items): bool
    {
        return $items->every(fn (Item $item) => DB::table('place_categories')->where('place_id', $point->id)->where('category_id', $item->category_id)->where('subtype', $item->subtype ?? '')->exists());
    }

    public function point(string $id, Collection $items, bool $lock = false): object
    {
        $query = DB::table('places')->where('id', $id);
        $point = ($lock ? $query->lockForUpdate() : $query)->first();
        $org = $point ? DB::table('organizations')->where('id', $point->organization_id) : null;
        $org = $org ? ($lock ? $org->lockForUpdate() : $org)->first() : null;
        if (! $point || ! $point->active || $point->review_status !== 'approved' || $org?->review_status !== 'approved' || ! $this->compatible($point, $items)) {
            throw ValidationException::withMessages(['point_id' => 'Choose an active point that explicitly accepts every selected category and subtype.']);
        }

        return $point;
    }

    public function area(string $id, float $latitude, float $longitude, bool $lock = false): object
    {
        $query = DB::table('service_areas')->where('id', $id)->where('active', true);
        $area = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $area || ! $this->contains(json_decode($area->geometry, true, flags: JSON_THROW_ON_ERROR), $latitude, $longitude)) {
            throw ValidationException::withMessages(['latitude' => 'The location is outside the selected study service area, or that area is inactive.']);
        }

        return $area;
    }

    public function contains(array $polygon, float $lat, float $lon): bool
    {
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($polygon['type'] ?? '') !== 'Polygon' || count($polygon['coordinates'] ?? []) !== 1) {
            return false;
        }
        $ring = $polygon['coordinates'][0];
        if (! is_array($ring) || collect($ring)->contains(fn ($vertex) => ! is_array($vertex) || count($vertex) !== 2 || ! is_numeric($vertex[0] ?? null) || ! is_numeric($vertex[1] ?? null))) {
            return false;
        }
        if (count($ring) < 4 || $ring[0] !== $ring[count($ring) - 1]) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$x, $y] = $ring[$i];
            [$u, $v] = $ring[$j];
            // Boundary-inclusive tolerance is 0.000001 degrees (roughly 0.11 m here), not a road distance.
            $length = hypot($u - $x, $v - $y);
            if ($length > 0 && abs(($lon - $x) * ($v - $y) - ($lat - $y) * ($u - $x)) / $length <= 0.000001 && $lon >= min($x, $u) - 0.000001 && $lon <= max($x, $u) + 0.000001 && $lat >= min($y, $v) - 0.000001 && $lat <= max($y, $v) + 0.000001) {
                return true;
            }
            if (($y > $lat) !== ($v > $lat) && $lon < ($u - $x) * ($lat - $y) / ($v - $y) + $x) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
