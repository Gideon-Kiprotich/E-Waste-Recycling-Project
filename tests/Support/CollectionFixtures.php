<?php

namespace Tests\Support;

use App\Actions\RegisterDevice;
use App\Models\Item;
use App\Models\User;
use App\Support\Devices\Questions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CollectionFixtures
{
    protected function directory(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $this->assertSame(0, Artisan::call('app:setup-collection-demo'));
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    protected function device(User $owner, string $category = 'laptop', ?string $subtype = null, array $extra = []): Item
    {
        $fields = ['category' => $category, 'subtype' => $subtype, 'idempotency_key' => Str::uuid()->toString(), 'data_status' => 'UNKNOWN', 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'NO', 'reason' => 'Synthetic fixture.', 'answers' => array_fill_keys(array_keys(Questions::tests($category, $subtype)), 'UNKNOWN')];
        if ($subtype === 'ict_cooling_fan') {
            $fields['answers']['fan_scope'] = 'YES';
            $fields['answers']['fan_electronics'] = 'BASIC';
        }

        return app(RegisterDevice::class)->register($owner, array_replace_recursive($fields, $extra), null);
    }

    protected function requestFields(array $ids, string $mode = 'pickup'): array
    {
        $fields = ['items' => $ids, 'estimated_kg' => array_fill_keys($ids, '0.250'), 'mode' => $mode, 'contact' => 'Synthetic contact only', 'preferred_date' => now('Africa/Nairobi')->addDay()->toDateString(), 'service_area_id' => DB::table('service_areas')->where('version_code', 'SYNTHETIC-STUDY-1.0')->value('id'), 'privacy_ack' => '1', 'idempotency_key' => Str::uuid()->toString()];
        if ($mode === 'pickup') {
            return array_merge($fields, ['address' => 'Synthetic landmark only', 'latitude' => '-1.280000', 'longitude' => '36.820000']);
        }

        return array_merge($fields, ['point_id' => DB::table('places')->where('code', 'synthetic-study-all-ict')->value('id')]);
    }
}
