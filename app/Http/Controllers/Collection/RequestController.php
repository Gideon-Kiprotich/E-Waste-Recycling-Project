<?php

namespace App\Http\Controllers\Collection;

use App\Actions\CollectionRequests;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Collection\Directory;
use App\Support\Collection\Eligibility;
use App\Support\Collection\Input;
use App\Support\Collection\PickupPoint;
use App\Support\Collection\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $requests = DB::table('requests')->where('consumer_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return response()->view('collection.index', compact('requests'))->header('Cache-Control', 'private, no-store');
    }

    public function restart(Request $request)
    {
        $request->session()->forget('collection_draft');

        return redirect()->route('requests.create');
    }

    private function draft(Request $request): array
    {
        $cipher = $request->session()->get('collection_draft');
        $draft = $cipher ? Crypt::decrypt($cipher) : ['owner' => $request->user()->id, 'completed' => 0, 'version' => 0, 'fields' => ['idempotency_key' => Str::uuid()->toString()]];
        abort_unless($draft['owner'] === $request->user()->id, 403);

        return $draft;
    }

    private function save(Request $request, array $draft): void
    {
        // File-backed sessions also contain ciphertext rather than private draft fields.
        $request->session()->put('collection_draft', Crypt::encrypt($draft));
    }

    public function create(Request $request, Directory $directory, int $step = 1)
    {
        $draft = $this->draft($request);
        $this->save($request, $draft);
        abort_unless($step >= 1 && $step <= 4 && $step <= $draft['completed'] + 1, 409, 'Complete the earlier request steps first.');
        $fields = $draft['fields'];
        $draftVersion = $draft['version'];
        $pickupConfirmed = PickupPoint::confirmed($draft);
        $items = Item::with('subject', 'category')->where('owner_user_id', $request->user()->id)->orderBy('subject_id')->get();
        $selected = $items->whereIn('subject_id', $fields['items'] ?? []);
        $points = $directory->points()->filter(fn ($point) => $selected->isNotEmpty() && $directory->compatible($point, $selected));
        $areas = DB::table('service_areas')->where('active', true)->orderBy('version_code')->get();
        $eligibility = [];
        foreach ($items as $item) {
            try {
                $snapshot = app(Eligibility::class)->inspect($item);
                $reserved = DB::table('request_items')->where('item_id', $item->subject_id)->where('active', true)->exists();
                $eligibility[$item->subject_id] = ['allowed' => ! $reserved, 'message' => $reserved ? 'Already reserved by an active request.' : (count($snapshot['review_reasons']) ? 'Additional safety/scope review required.' : 'Candidate for review; not scheduled.')];
            } catch (ValidationException) {
                $eligibility[$item->subject_id] = ['allowed' => false, 'message' => 'Not eligible for a new request.'];
            }
        }

        return response()->view('collection.wizard', compact('step', 'fields', 'items', 'selected', 'points', 'areas', 'eligibility', 'draftVersion', 'pickupConfirmed'))->header('Cache-Control', 'private, no-store');
    }

    public function advance(Request $request, CollectionRequests $action, int $step)
    {
        $draft = $this->draft($request);
        abort_unless($step >= 1 && $step <= 4 && $step <= $draft['completed'] + 1, 409, 'Complete the earlier steps first.');
        $version = $request->input('draft_version');
        abort_unless((is_int($version) || (is_string($version) && preg_match('/\A[0-9]{1,9}\z/', $version))) && (int) $version === $draft['version'], 409, 'This wizard has changed. Reload the current step before continuing.');
        if ($step === 4) {
            try {
                $final = Input::validate($request->except('draft_version'), 4);
                if ($final['idempotency_key'] !== $draft['fields']['idempotency_key']) {
                    abort(409, 'This form is stale. Reopen the request wizard.');
                }
                // Existing commands still replay through the original payload-hash check.
                $retry = DB::table('commands')->where('actor_id', $request->user()->id)->where('action', 'submit_request')->where('idempotency_key', $final['idempotency_key'])->where('status', 'completed')->exists();
                if ($draft['fields']['mode'] === 'pickup' && ! $retry) {
                    PickupPoint::requireConfirmation($draft);
                }
                $id = $action->submit($request->user(), array_merge($draft['fields'], $final));
            } catch (ValidationException $e) {
                if (isset($e->errors()['pickup_point'])) {
                    $draft['completed'] = 2;
                    $draft['version']++;
                    $this->save($request, $draft);

                    return redirect()->route('requests.step', ['step' => 3])->withErrors($e->errors());
                }

                return redirect()->route('requests.step', ['step' => 4])->withErrors($e->errors());
            }

            return redirect()->route('requests.show', $id)->with('status', 'Your request was submitted. It is awaiting review; the preferred date is not a booking.')->with('status_type', 'success');
        }
        try {
            $confirmPoint = $step === 3 && $request->input('intent') === 'confirm_pickup' && $draft['fields']['mode'] === 'pickup';
            if ($request->exists('intent') && ! $confirmPoint) {
                throw ValidationException::withMessages(['submission' => 'Unsupported location action.']);
            }
            if ($request->exists('pickup_changed')) {
                if ($step !== 3 || $draft['fields']['mode'] !== 'pickup' || $request->input('pickup_changed') !== '1') {
                    throw ValidationException::withMessages(['submission' => 'Unsupported location change.']);
                }
                unset($draft['pickup_confirmation']);
            }
            if ($step === 3 && $request->input('mode') !== $draft['fields']['mode']) {
                throw ValidationException::withMessages(['mode' => 'Return to Collection method to change the method.']);
            }
            $fields = Input::validate($request->except(array_merge(['draft_version', 'pickup_changed'], $confirmPoint ? ['intent'] : [])), $step, $confirmPoint);
            if ($step === 1) {
                $action->available($action->items($request->user(), $fields['items']));
            }
            if ($step === 3) {
                if (! $confirmPoint) {
                    $action->futureDate($fields['preferred_date']);
                }
                $action->location($fields, $action->items($request->user(), $draft['fields']['items']));
                if ($fields['mode'] === 'pickup' && ! $confirmPoint) {
                    PickupPoint::requireConfirmation($draft, $fields);
                }
            }
        } catch (ValidationException $e) {
            $draft['completed'] = min($draft['completed'], $step - 1);
            $draft['version']++;
            $allowed = match ($step) {
                1 => [], 2 => ['mode'], 3 => ['address', 'latitude', 'longitude', 'point_id', 'service_area_id', 'contact', 'preferred_date']
            };
            foreach (array_intersect_key($request->all(), array_flip($allowed)) as $key => $value) {
                if (is_string($value) && strlen($value) <= 500) {
                    $draft['fields'][$key] = $value;
                }
            }
            if ($step === 1 && is_array($request->input('items'))) {
                $ids = array_values(array_filter($request->input('items'), fn ($id) => is_string($id) && Str::isUuid($id)));
                $ids = Item::where('owner_user_id', $request->user()->id)->whereIn('subject_id', array_slice($ids, 0, 25))->pluck('subject_id')->all();
                $draft['fields']['items'] = $ids;
                $masses = $request->input('estimated_kg', []);
                $draft['fields']['estimated_kg'] = is_array($masses) ? array_filter(array_intersect_key($masses, array_flip($ids)), fn ($mass) => is_string($mass) && strlen($mass) <= 30) : [];
            }
            if (! PickupPoint::confirmed($draft)) {
                unset($draft['pickup_confirmation']);
            }
            $this->save($request, $draft);

            return redirect()->route($step === 1 ? 'requests.create' : 'requests.step', $step === 1 ? [] : ['step' => $step])->withErrors($e->errors());
        }
        if ($step === 1 && ($draft['fields']['items'] ?? []) !== $fields['items']) {
            $draft['fields'] = ['idempotency_key' => $draft['fields']['idempotency_key']];
            unset($draft['pickup_confirmation']);
        }
        if ($step === 2 && ($draft['fields']['mode'] ?? null) !== $fields['mode']) {
            foreach (['point_id', 'address', 'latitude', 'longitude'] as $key) {
                unset($draft['fields'][$key]);
            }
            unset($draft['pickup_confirmation']);
        }
        $draft['fields'] = array_merge($draft['fields'], $fields);
        if ($confirmPoint) {
            $draft['pickup_confirmation'] = PickupPoint::key($fields);
        }
        $draft['completed'] = $confirmPoint ? 2 : $step;
        $draft['version']++;
        $this->save($request, $draft);

        if ($confirmPoint) {
            return redirect()->route('requests.step', ['step' => 3])->with('status', 'Pickup point confirmed. Complete the location details, then continue. This does not confirm service or a booking.')->with('status_type', 'info');
        }

        return redirect()->route('requests.step', ['step' => $step + 1]);
    }

    public function show(Request $request, CollectionRequests $action, string $collection)
    {
        $record = $action->owned($request->user(), $collection);
        $private = DB::table('request_private_details')->where('id', $record->private_details_id)->first();
        $contact = Crypt::decryptString($private->encrypted_contact);
        $address = $private->encrypted_address ? Crypt::decryptString($private->encrypted_address) : null;
        $rows = DB::table('request_items')->where('request_id', $collection)->orderBy('item_id')->get();
        $items = $action->items($request->user(), $rows->pluck('item_id')->all())->keyBy('subject_id');
        $history = DB::table('domain_events')->where('aggregate_kind', 'request')->where('aggregate_id', $collection)->orderByDesc('aggregate_version')->paginate(10);
        $point = $record->point_id ? DB::table('places')->where('id', $record->point_id)->first() : null;
        $area = DB::table('service_areas')->where('id', $record->service_area_id)->first();
        $reviewState = app(Review::class)->state($record);

        return response()->view('collection.show', compact('record', 'private', 'contact', 'address', 'rows', 'items', 'history', 'point', 'area', 'reviewState'))->header('Cache-Control', 'private, no-store');
    }

    public function change(Request $request, CollectionRequests $action, string $collection)
    {
        $action->change($request->user(), $collection, $request->except('_token'));

        return redirect()->route('requests.show', $collection)->with('status', 'Your request change was saved. Earlier history is retained.')->with('status_type', 'success');
    }

    public function points(Directory $directory)
    {
        $points = $directory->points();
        $capabilities = DB::table('place_categories')->join('categories', 'categories.id', '=', 'place_categories.category_id')->select('place_categories.*', 'categories.label')->get()->groupBy('place_id');

        return view('collection.points', compact('points', 'capabilities'));
    }
}
