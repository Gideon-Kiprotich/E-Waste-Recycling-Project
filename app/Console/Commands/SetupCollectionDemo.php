<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupCollectionDemo extends Command
{
    protected $signature = 'app:setup-collection-demo';

    protected $description = 'Add synthetic local study points and boundary without changing existing rows';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        if (! app()->environment('local') || ($c['driver'] ?? '') !== 'pgsql' || ! in_array($c['database'], ['ewaste_development', 'ewaste_testing'], true) || $c['host'] !== '127.0.0.1' || (string) $c['port'] !== '5432' || $c['username'] !== 'ewaste_app' || ! empty($c['url'])) {
            $this->error('Demo setup requires local environment and a dedicated project PostgreSQL connection.');

            return self::FAILURE;
        }
        $identity = DB::selectOne('SELECT current_database() AS database, current_user AS role, pg_get_userbyid(datdba) AS owner, rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS elevated FROM pg_database JOIN pg_roles ON rolname = current_user WHERE datname = current_database()');
        if ($identity->database !== $c['database'] || $identity->role !== 'ewaste_app' || $identity->owner !== 'ewaste_app' || $identity->elevated) {
            $this->error('Unexpected database ownership or privileges.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['collection-demo-setup']);
            $org = DB::table('organizations')->where('code', 'synthetic-study-operator-1')->first();
            if ($org && ! $org->synthetic) {
                throw new \RuntimeException('Synthetic setup code is occupied by non-synthetic data.');
            }
            if (! $org) {
                $id = Str::uuid()->toString();
                DB::table('organizations')->insert(['id' => $id, 'code' => 'synthetic-study-operator-1', 'name' => 'Nairobi synthetic study operator — no real service', 'kind' => 'collector', 'review_status' => 'approved', 'synthetic' => true, 'reviewed_at' => now(), 'review_reason' => 'Simulated study configuration, not operator certification.']);
                $org = DB::table('organizations')->where('id', $id)->first();
            }
            $area = DB::table('service_areas')->where('version_code', 'SYNTHETIC-STUDY-1.0')->first();
            if ($area && ! $area->synthetic) {
                throw new \RuntimeException('Synthetic version is occupied by non-synthetic data.');
            }
            if (! $area) {
                DB::table('service_areas')->insert(['id' => Str::uuid()->toString(), 'version_code' => 'SYNTHETIC-STUDY-1.0', 'geometry' => json_encode(['type' => 'Polygon', 'coordinates' => [[[36.75, -1.35], [36.9, -1.35], [36.9, -1.2], [36.75, -1.2], [36.75, -1.35]]]]), 'provenance' => 'Hand-defined rectangular local study zone; not the official Nairobi County boundary.', 'synthetic' => true, 'active' => true]);
            }
            foreach (['all-ict' => [true, true], 'whole-devices' => [true, false], 'inactive' => [false, true]] as $suffix => [$active,$parts]) {
                $code = 'synthetic-study-'.$suffix;
                $existing = DB::table('places')->where('code', $code)->first();
                if ($existing) {
                    if (! $existing->synthetic) {
                        throw new \RuntimeException('Synthetic point code is occupied by non-synthetic data.');
                    }

                    continue;
                }
                $id = Str::uuid()->toString();
                DB::table('places')->insert(['id' => $id, 'organization_id' => $org->id, 'code' => $code, 'kind' => 'collection_point', 'name' => 'Nairobi synthetic study point: '.$suffix, 'latitude' => -1.28, 'longitude' => 36.82, 'hours' => json_encode(['note' => 'Nairobi demonstration only; no real operating hours.']), 'active' => $active, 'review_status' => 'approved', 'synthetic' => true]);
                foreach (DB::table('categories')->where('supported', true)->get() as $category) {
                    $subtypes = match ($category->code) {
                        'standalone_part' => $parts ? ['hdd', 'ssd', 'ict_cooling_fan'] : [], 'peripheral' => ['router', 'keyboard'], default => ['']
                    };
                    foreach ($subtypes as $subtype) {
                        DB::table('place_categories')->insert(['place_id' => $id, 'category_id' => $category->id, 'subtype' => $subtype]);
                    }
                }
            }
        });
        $this->info('Synthetic study directory is available. Existing rows retained; no real acceptance or pickup is promised.');

        return self::SUCCESS;
    }
}
