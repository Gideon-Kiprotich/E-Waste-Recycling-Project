<?php

namespace App\Support\Reporting;

use App\Models\User;
use App\Support\Collection\Review;
use App\Support\Devices\Identifiers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportSession
{
    public static function fingerprint(Request $request): ?string
    {
        return $request->hasSession() ? Identifiers::digest($request->session()->getId(), 'report_export_session') : null;
    }

    public static function invalidate(User $actor): void
    {
        if (! DB::getSchemaBuilder()->hasTable('report_exports')) {
            return;
        }
        DB::transaction(function () use ($actor) {
            Review::lock();
            $ids = DB::table('report_exports')->where('requester_id', $actor->id)->whereIn('state', ['generating', 'ready'])->lockForUpdate()->pluck('id');
            DB::table('report_exports')->whereIn('id', $ids)->update(['state' => 'invalidated']);
            foreach ($ids as $id) {
                DB::table('report_export_accesses')->insert(['id' => Str::uuid()->toString(), 'export_id' => $id, 'actor_id' => $actor->id, 'outcome' => 'invalidated', 'recorded_at' => now()]);
            }
        });
    }
}
