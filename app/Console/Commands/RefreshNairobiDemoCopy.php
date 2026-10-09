<?php

namespace App\Console\Commands;

use App\Support\Collection\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefreshNairobiDemoCopy extends Command
{
    protected $signature = 'app:refresh-nairobi-demo-copy {--apply : Apply the displayed eligible copy changes}';

    protected $description = 'Preview narrowly scoped untouched synthetic demo names; never rename user records or operational references';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        if (! app()->environment('local') || ($c['driver'] ?? '') !== 'pgsql' || ! in_array($c['database'], ['ewaste_development', 'ewaste_testing'], true) || $c['host'] !== '127.0.0.1' || (string) $c['port'] !== '5432' || $c['username'] !== 'ewaste_app' || ! empty($c['url'])) {
            $this->error('Demo copy maintenance requires a dedicated local project connection.');

            return self::FAILURE;
        }
        $identity = DB::selectOne('SELECT current_database() AS database,current_user AS role,pg_get_userbyid(datdba) AS owner,rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS elevated FROM pg_database JOIN pg_roles ON rolname=current_user WHERE datname=current_database()');
        if ($identity->database !== $c['database'] || $identity->role !== 'ewaste_app' || $identity->owner !== 'ewaste_app' || $identity->elevated) {
            $this->error('Unexpected database ownership or privileges.');

            return self::FAILURE;
        }

        return DB::transaction(function (): int {
            Review::lock();
            $copies = [['organizations', 'synthetic-study-operator-1', 'Synthetic study operator — no real service', 'Nairobi synthetic study operator — no real service']];
            foreach (['all-ict', 'whole-devices', 'inactive'] as $suffix) {
                $copies[] = ['places', 'synthetic-study-'.$suffix, 'Synthetic demonstration: '.$suffix, 'Nairobi synthetic study point: '.$suffix];
            }
            foreach ($copies as [$table, $code, $old, $new]) {
                $row = DB::table($table)->where('code', $code)->lockForUpdate()->first();
                $kind = $table === 'organizations' ? 'organization' : 'place';
                $eligible = $row && $row->synthetic && $row->name === $old && (int) $row->version === 1 && $row->encrypted_contact === null && ! DB::table('directory_revisions')->where('record_kind', $kind)->where('record_id', $row->id)->exists();
                if ($eligible && $kind === 'place') {
                    $eligible = ! DB::table('requests')->where('point_id', $row->id)->exists() && (! Schema::hasColumn('parties', 'place_id') || ! DB::table('parties')->where('place_id', $row->id)->exists());
                }
                if ($eligible && $kind === 'organization') {
                    $eligible = ! DB::table('places')->where('organization_id', $row->id)->exists() && (! Schema::hasColumn('memberships', 'organization_id') || ! DB::table('memberships')->where('organization_id', $row->id)->exists()) && (! Schema::hasColumn('parties', 'organization_id') || ! DB::table('parties')->where('organization_id', $row->id)->exists());
                }
                if ($eligible) {
                    // Name-only maintenance of untouched, unreferenced seed copy; no review/operational claim changes.
                    $this->line($code.' → '.$new.($this->option('apply') ? ' [apply]' : ' [preview]'));
                    if ($this->option('apply')) {
                        DB::table($table)->where('id', $row->id)->update(['name' => $new]);
                    }
                } else {
                    $this->line($code.' [retained: absent, edited, already updated or operationally referenced]');
                }
            }
            $this->info($this->option('apply') ? 'Eligible copy changes applied; identities and all operational fields retained.' : 'Preview only. No rows changed. Rerun with --apply after reviewing.');

            return self::SUCCESS;
        });
    }
}
