<?php

namespace App\Http\Controllers\Devices;

use App\Actions\RegisterDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\ObservationRequest;
use App\Http\Requests\Devices\RegisterDeviceRequest;
use App\Models\Category;
use App\Models\Item;
use App\Support\Devices\Questions;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        $items = Item::with('subject', 'category')->join('subjects', 'subjects.id', '=', 'items.subject_id')->select('items.*')->where('owner_user_id', $request->user()->id)->orderByDesc('subjects.created_at')->orderByDesc('subjects.id')->paginate(12);

        return view('devices.index', ['items' => $items, 'fieldIds' => ['public_code' => 'public_code']]);
    }

    public function create(Request $request): View
    {
        $category = old('category', $request->query('category', 'laptop'));
        $subtype = old('subtype', $request->query('subtype'));
        $category = is_string($category) && isset(Questions::CATEGORIES[$category]) ? $category : 'laptop';
        $subtype = $category === 'peripheral' && in_array($subtype, ['router', 'keyboard'], true) ? $subtype : null;
        $categories = Category::where('supported', true)->orderBy('code')->get();
        $tests = Questions::tests($category, $subtype);

        return view('devices.create', compact('category', 'subtype', 'categories', 'tests'))->with('commandKey', $this->commandKey());
    }

    public function store(RegisterDeviceRequest $request, RegisterDevice $action): RedirectResponse
    {
        $item = $action->register($request->user(), $request->validated(), $request->file('photo'));

        return redirect()->route('items.show', $item->subject_id)->with('status', 'Your device is registered. Its identity label is ready.')->with('status_type', 'success');
    }

    public function show(Request $request, string $item): View
    {
        $item = $this->owned($request, $item);
        $history = $item->observations()->orderByDesc('revision')->paginate(10);
        $current = $item->observations()->orderByDesc('revision')->firstOrFail();
        $photo = DB::table('evidence_links')->join('attachments', 'attachments.id', '=', 'evidence_links.attachment_id')->where('evidence_links.subject_id', $item->subject_id)->whereNull('attachments.redacted_at')->value('attachments.id');
        $tests = Questions::tests($item->category->code, $item->subtype);

        return view('devices.show', compact('item', 'history', 'current', 'photo', 'tests'));
    }

    public function edit(Request $request, string $item): View
    {
        $item = $this->owned($request, $item);
        Gate::authorize('update', $item);
        $current = $item->observations()->orderByDesc('revision')->firstOrFail();
        $tests = Questions::tests($item->category->code, $item->subtype);

        return view('devices.edit', compact('item', 'current', 'tests'))->with('commandKey', $this->commandKey());
    }

    public function update(ObservationRequest $request, RegisterDevice $action, string $item): RedirectResponse
    {
        $item = $this->owned($request, $item);
        try {
            $item = $action->revise($request->user(), $item, $request->validated());
        } catch (QueryException) {
            throw ValidationException::withMessages(['revision' => 'The self-report could not be saved. Retry with the same form reference.']);
        }

        return redirect()->route('items.show', $item->subject_id)->with('status', 'Your self-report was updated. Earlier revisions are retained.')->with('status_type', 'success');
    }

    public function lookup(Request $request, string $public_code): RedirectResponse
    {
        $item = Item::where('owner_user_id', $request->user()->id)->whereHas('subject', fn ($query) => $query->where('public_code', $public_code))->firstOrFail();
        Gate::authorize('view', $item);

        return redirect()->route('items.show', $item->subject_id);
    }

    public function manualLookup(Request $request): RedirectResponse
    {
        $fields = $request->validate(['public_code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/']]);

        return $this->lookup($request, $fields['public_code']);
    }

    public function label(Request $request, string $item): View
    {
        return view('devices.label', ['item' => $this->owned($request, $item)]);
    }

    public function qr(Request $request, string $item): Response
    {
        $item = $this->owned($request, $item);
        $payload = route('items.lookup', ['public_code' => $item->subject->public_code]);
        $result = (new PngWriter)->write(new QrCode(data: $payload, size: 360, margin: 24));

        return response($result->getString(), 200, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$item->subject->public_code.'.png"',
        ]);
    }

    public function photo(Request $request, string $item, string $attachment): Response
    {
        $item = $this->owned($request, $item);
        $photo = DB::table('attachments')->join('evidence_links', 'attachments.id', '=', 'evidence_links.attachment_id')->where('attachments.id', $attachment)->where('evidence_links.subject_id', $item->subject_id)->whereNull('redacted_at')->select('attachments.*')->first();
        abort_unless($photo && Storage::disk('device_photos')->exists($photo->storage_key), 404);

        return response(Storage::disk('device_photos')->get($photo->storage_key), 200, ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="device-photo.png"']);
    }

    private function owned(Request $request, string $id): Item
    {
        $item = Item::with('subject', 'category')->where('owner_user_id', $request->user()->id)->findOrFail($id);
        Gate::authorize('view', $item);

        return $item;
    }

    private function commandKey(): string
    {
        $key = old('idempotency_key');

        return is_string($key) && Str::isUuid($key) ? $key : Str::uuid()->toString();
    }
}
