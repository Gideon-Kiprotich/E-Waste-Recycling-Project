<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\Review;
use App\Support\Notices\Links;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public const TABLES = ['assignment' => 'assignment_notifications', 'receipt' => 'receipt_notifications', 'handover' => 'handover_notifications', 'assessment' => 'assessment_notifications', 'restriction' => 'restriction_notifications', 'milestone' => 'milestone_notifications'];

    public function index(Request $request)
    {
        $queries = [];
        foreach (self::TABLES as $family => $table) {
            $q = DB::table($table.' as n')->join('notification_outbox as o', 'o.id', '=', 'n.outbox_id')->join('domain_events as e', 'e.id', '=', 'o.event_id')->where('n.recipient_id', $request->user()->id)->selectRaw('? AS family,n.id,n.read_at,o.event_id,e.type,e.recorded_at', [$family]);
            $queries[] = $q;
        }
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }
        $notices = DB::query()->fromSub($union, 'inbox')->orderByDesc('recorded_at')->orderByDesc('id')->paginate(25);
        $notices->through(function ($notice) use ($request) {
            $notice->url = app(Links::class)->for($request->user(), $notice);

            return $notice;
        });
        $unavailable = DB::table('notification_outbox')->where('recipient_id', $request->user()->id)->whereIn('state', ['failed', 'suppressed'])->count();
        $failures = $request->user()->role() === Role::Administrator ? DB::table('notification_outbox')->whereIn('state', ['failed', 'suppressed'])->orderBy('id')->paginate(25, ['id', 'state', 'attempts', 'delivery_error'], 'failures') : collect();

        return response()->view('reporting.inbox', compact('notices', 'unavailable', 'failures'))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $family, string $notice)
    {
        abort_unless(isset(self::TABLES[$family]), 404);
        DB::transaction(function () use ($request, $family, $notice) {
            Review::lock();
            $actor = User::with('membership')->findOrFail($request->user()->id);
            abort_unless($actor->canSignIn() && $actor->session_version === $request->user()->session_version, 403);
            $row = DB::table(self::TABLES[$family])->where('id', $notice)->where('recipient_id', $actor->id)->lockForUpdate()->firstOrFail();
            if (! $row->read_at) {
                DB::table(self::TABLES[$family])->where('id', $row->id)->update(['read_at' => now()]);
            }
        });

        return redirect()->route('inbox.index')->with('status', 'Notice marked read.');
    }

    public function retry(Request $request, string $outbox)
    {
        DB::transaction(function () use ($request, $outbox) {
            Review::lock();
            $actor = User::with('membership')->findOrFail($request->user()->id);
            abort_unless($actor->canSignIn() && $actor->session_version === $request->user()->session_version && $actor->role() === Role::Administrator, 403);
            $row = DB::table('notification_outbox')->where('id', $outbox)->where('state', 'failed')->lockForUpdate()->firstOrFail();
            $recipient = User::with('membership')->find($row->recipient_id);
            abort_unless($recipient?->canSignIn(), 409, 'Recipient authority is unavailable.');
            if ($row->delivery_context !== null) {
                abort_unless(json_decode($row->delivery_context, true) === json_decode(DB::selectOne('SELECT restriction_actor_context(?)::text AS context', [$row->recipient_id])->context, true), 409, 'Recipient scope changed; this delivery cannot be revived.');
            }
            DB::table('notice_delivery_retries')->insert(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'actor_id' => $request->user()->id, 'previous_attempts' => $row->attempts, 'recorded_at' => now()]);
            DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'delivery_error' => null]);
        });

        return redirect()->route('inbox.index')->with('status', 'Supported delivery queued for retry. No historical events were replayed.');
    }
}
