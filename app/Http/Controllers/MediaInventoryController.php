<?php

namespace App\Http\Controllers;

use App\Actions\InventoryMedia;
use App\Enums\Role;
use App\Support\MediaInventory\Context;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaInventoryController extends Controller
{
    public function show(Request $request, Context $ctx, string $subject)
    {
        $private = $request->user()->role() === Role::Processor;
        $row = $private ? $ctx->parent($request->user(), $subject) : DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->where('i.subject_id', $subject)->where('i.owner_user_id', $request->user()->id)->where('i.intake_class', 'whole_device')->firstOrFail(['i.subject_id', 's.public_code']);
        $coverage = $ctx->coverage($subject);
        $entries = $private ? $ctx->entries($subject) : collect();
        $history = $private ? DB::table('media_inventory_records')->where('parent_id', $subject)->orderByDesc('version')->get() : collect();
        $authorizations = $private ? DB::table('media_authorizations')->where('parent_id', $subject)->orderByDesc('recorded_at')->get() : collect();
        $candidates = $private ? $ctx->candidates($request->user(), $row) : collect();
        $mode = $request->query('action');
        $mode = $private && is_string($mode) && in_array($mode, ['identify', 'link', 'unresolved', 'correct', 'attest', 'authorize'], true) ? $mode : null;
        $selectedCode = $request->query('medium');
        $selectedMedium = is_string($selectedCode) ? $entries->firstWhere('medium_id', $selectedCode) : null;
        $selectedMedium = $selectedMedium && $selectedMedium->state === 'confirmed' && in_array($selectedMedium->media_kind, ['hdd', 'ssd'], true) && DB::table('media_origins')->where('subject_id', $selectedMedium->medium_id)->exists() ? $selectedMedium : null;
        $authorizationVersion = $selectedMedium ? (int) (DB::table('media_authorization_current')->where('subject_id', $selectedMedium->medium_id)->value('version') ?? 0) : 0;
        $version = $ctx->version($subject);
        $token = $private ? $ctx->token($request->user(), $row) : null;
        $blockers = $private ? app(\App\Support\Assessments\Context::class)->blockers($row) : [];
        if ($private && app(\App\Support\Assessments\Context::class)->restricted($subject)) {
            $blockers[] = 'An inspection hard restriction remains unresolved; inventory cannot clear it.';
        }

        $fieldIds = array_fill_keys(['entry_id', 'media_kind', 'model', 'identifier', 'existing_code', 'entry_state', 'completeness', 'medium_id', 'expected_authorization_version', 'placement', 'observed_at', 'evidence_kind', 'evidence', 'reference_issuer', 'reference_code', 'reference_attested', 'reason', 'na_justification'], '');
        foreach ($fieldIds as $key => $_) {
            $fieldIds[$key] = $key;
        }
        foreach (Context::checks($mode ?? '') as $key => $_) {
            $fieldIds['checks.'.$key] = 'checks_'.$key;
        }

        return response()->view('media-inventory.show', compact('private', 'row', 'coverage', 'entries', 'history', 'authorizations', 'candidates', 'mode', 'version', 'token', 'blockers', 'fieldIds', 'selectedMedium', 'authorizationVersion'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, InventoryMedia $action, string $subject, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $subject, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('media-inventory.show', $subject)->with('status', 'Media inventory or authorization recorded. Review live coverage; no physical treatment, removal, transfer or release was performed.');
    }

    public function label(Request $request, string $medium)
    {
        $row = app(\App\Support\Assessments\Context::class)->authorized($request->user(), $medium);
        abort_unless(DB::table('storage_assets')->where('subject_id', $medium)->exists(), 404);

        return response()->view('media-inventory.label', compact('row'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function qr(Request $request, string $medium)
    {
        $row = app(\App\Support\Assessments\Context::class)->authorized($request->user(), $medium);
        abort_unless(DB::table('storage_assets')->where('subject_id', $medium)->exists(), 404);
        $result = (new PngWriter)->write(new QrCode(data: route('items.lookup', ['public_code' => $row->public_code]), size: 360, margin: 24));

        return response($result->getString(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function evidence(Request $request, Context $ctx, string $kind, string $record)
    {
        abort_unless(in_array($kind, ['inventory', 'authorization'], true), 404);
        $r = DB::table($kind === 'inventory' ? 'media_inventory_records' : 'media_authorizations')->where('id', $record)->firstOrFail();
        $ctx->parent($request->user(), $r->parent_id);
        $attachment = DB::table('attachments')->where('id', $r->attachment_id)->where('privacy_class', 'private')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($attachment->storage_key), 404);

        return Storage::disk('directory_evidence')->download($attachment->storage_key, 'private-inventory-evidence.'.($attachment->mime === 'application/pdf' ? 'pdf' : ($attachment->mime === 'image/png' ? 'png' : 'jpg')), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
