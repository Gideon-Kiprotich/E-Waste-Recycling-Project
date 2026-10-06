<?php

namespace App\Http\Controllers\Administration;

use App\Actions\ManageDirectory;
use App\Http\Controllers\Controller;
use App\Support\Collection\DirectoryInput;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DirectoryController extends Controller
{
    public function index()
    {
        $organizations = DB::table('organizations')->orderBy('name')->orderBy('id')->paginate(12, ['id', 'name', 'kind', 'review_status', 'synthetic', 'version'], 'organizations_page');
        $places = DB::table('places')->join('organizations', 'organizations.id', '=', 'places.organization_id')->select('places.id', 'places.name', 'places.kind', 'places.review_status', 'places.active', 'places.synthetic', 'places.version', 'organizations.name as organization_name')->orderBy('places.name')->orderBy('places.id')->paginate(12, ['*'], 'places_page');

        return response()->view('administration.directory-index', compact('organizations', 'places'))->header('Cache-Control', 'private, no-store');
    }

    public function form(string $kind, ?string $record = null)
    {
        $table = $kind === 'organization' ? 'organizations' : 'places';
        $entity = $record ? DB::table($table)->where('id', $record)->firstOrFail() : null;
        $organizations = DB::table('organizations')->orderBy('name')->get(['id', 'name', 'synthetic', 'review_status']);
        $options = DirectoryInput::capabilities();
        $selected = $entity && $kind === 'place' ? DB::table('place_categories')->join('categories', 'categories.id', '=', 'place_categories.category_id')->where('place_id', $entity->id)->get(['categories.code', 'place_categories.subtype'])->map(fn ($row) => $row->code.':'.$row->subtype)->all() : [];
        $contact = $entity?->encrypted_contact ? Crypt::decryptString($entity->encrypted_contact) : '';
        $history = DB::table('directory_revisions')->where('record_kind', $kind)->where('record_id', $record)->orderByDesc('version')->paginate(10);
        $evidence = DB::table('evidence_links')->join('attachments', 'attachments.id', '=', 'evidence_links.attachment_id')->where('record_kind', $kind)->where('record_id', $record)->whereNull('redacted_at')->get(['attachments.id', 'attachments.mime', 'attachments.size_bytes']);
        $linkedPlaces = $entity && $kind === 'organization' ? DB::table('places')->where('organization_id', $entity->id)->orderBy('name')->get(['id', 'name', 'kind', 'active', 'review_status']) : collect();

        return response()->view('administration.directory-form', compact('kind', 'entity', 'organizations', 'options', 'selected', 'contact', 'history', 'evidence', 'linkedPlaces'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, ManageDirectory $action, string $kind, ?string $record = null)
    {
        $id = $action->save($request->user(), $kind, $record, $request->except('_token', 'evidence'), self::upload($request));

        return redirect()->route('admin.directory.edit', [$kind, $id])->with('status', 'Directory review saved. Outstanding requests require fresh review.')->with('status_type', 'success');
    }

    public static function upload(Request $request): ?UploadedFile
    {
        $file = $request->file('evidence') ?? $request->input('evidence');
        if ($file !== null && ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['evidence' => 'Choose one supported evidence file.']);
        }

        return $file;
    }

    public function evidence(string $attachment)
    {
        $file = DB::table('attachments')->join('evidence_links', 'attachments.id', '=', 'evidence_links.attachment_id')->where('attachments.id', $attachment)->whereIn('record_kind', ['organization', 'place', 'request_review'])->whereNull('redacted_at')->select('attachments.*')->first();
        abort_unless($file && Storage::disk('directory_evidence')->exists($file->storage_key), 404);
        $extension = $file->mime === 'application/pdf' ? 'pdf' : 'png';

        return response(Storage::disk('directory_evidence')->get($file->storage_key), 200, ['Content-Type' => $file->mime, 'Content-Disposition' => 'attachment; filename="review-evidence.'.$extension.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }
}
