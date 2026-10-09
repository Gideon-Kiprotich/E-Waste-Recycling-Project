<?php

namespace App\Http\Controllers\Assignments;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Collection\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        $notices = DB::table('assignment_notifications as n')->join('domain_events as e', 'e.id', '=', 'n.event_id')->join('assignments as a', 'a.id', '=', 'n.assignment_id')->join('requests as r', 'r.id', '=', 'a.request_id')->where('n.recipient_id', $request->user()->id)->orderByDesc('e.recorded_at')->orderByDesc('n.id')->paginate(12, ['n.id', 'n.read_at', 'n.assignment_id', 'a.request_id', 'e.type', 'e.recorded_at', 'r.public_code']);

        return response()->view('assignments.notices', compact('notices'))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notice)
    {
        DB::transaction(function () use ($request, $notice): void {
            Review::lock();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $actor->setRelation('membership', $actor->membership()->lockForUpdate()->first());
            abort_unless($actor->canSignIn() && $actor->session_version === $request->user()->session_version, 403);
            $row = DB::table('assignment_notifications')->where('id', $notice)->where('recipient_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $row->read_at) {
                DB::table('assignment_notifications')->where('id', $row->id)->update(['read_at' => now()]);
            }
        });

        return redirect()->route('assignment.notices')->with('status', 'Notice marked read.');
    }
}
