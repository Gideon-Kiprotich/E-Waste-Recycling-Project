# Phase 2 — Device Registration and Detail

Date: 4 October 2026 (Africa/Nairobi). Branch: `phase-2-devices`, created from completed `main` at `45b52aa6d0b2d68f118600edb492428a142c15fb`; the starting working tree was clean.

Status: implemented; required automated/runtime checks passed. Manual browser acceptance remains required before phase closure. No merge, push or Phase 3 work is included.

## Requirements and screen coverage

Reviewed D2 §§3.4, 4–5, privacy/access matrix and AT03/AT05; D3 US03/UC03, §§4.1/4.5–4.7, 5.3–5.5, category questions in §6.4, S03 and applicable AT03/AT05 cases. D2 controls scope, D3 behavior and the supplied Stitch references presentation.

- FR05/FR06, US03/UC03, AT03 registration subset: one record per registration, permanent identity, six categories/seven category-subtypes, optional model/serial/photo, explicit unknowns, private possible-duplicate warning and retained self-report revisions.
- FR02, FR15, NF01 and AT05 owner subset: scoped list/detail/edit/manual lookup/QR/print/download/photo routes, real QR with no personal payload, denied cross-owner and staff access, unchanged identity on reprinting.
- FR27 registration subset: only actual registration and retained consumer observations are displayed. Collection/assessment/batch milestones are not fabricated.
- FR34 and D3 command contract: validated input, retry keys, expected versions, locked eligibility checks, atomic identity/observation/event/custody/outbox writes and failure compensation for private blobs.

Reviewed original S02 `s02_consumer_overview/code.html`/`screen.png` (device list/empty state) and S03 `s03_device_registration_detail/code.html`/`screen.png` plus shared design guidance. These originals are now tracked unchanged as references. They are not application assets or evidence of a rendered browser pass.

Visual adjustments: local Blade/CSS retain the civic green palette, header/navigation, white cards, green fact panels, two-column form/detail composition and QR panel. The sparse owned-device list uses responsive cards with pagination. Separate real pages replace simulated register/record tabs; a server-rendered category picker works without JavaScript. Model is optional; unknowns are explicit; unsupported UPS/switch options, estimated routing mass, draft simulation, county certification/blockchain claims, invented owners/contacts, technician findings and later timeline/actions are omitted. No external font/icon/image/script imports or notification library was added.

## Schema, identity and history

Migration `2026_10_04_000002_create_device_registration` ran on both dedicated PostgreSQL databases. It creates only the required foundation: `categories`, `subjects`, `items`, `item_observations`, `attachments`, `evidence_links`, `parties`, `custody_current`, `commands`, `domain_events` and `notification_outbox`. The last four structures support D3's initial owner custody and transactional command/event/outbox contract; no requests, assignments, facilities, transfers, assessments, batches, routing or reports are generated.

Six category reference rows are migration configuration, not sample business data. Supported values are laptop, desktop, phone, flat-panel monitor, printer and peripheral; only router/keyboard subtypes are accepted for peripherals. Required self-report questions map D3 §6.4's functional observations to controlled codes in `Questions`, frozen as `SELF-REPORT-1.0`. Answers are PASS/FAIL/UNKNOWN; no decisive question in this self-report schema permits N/A. Shared declarations are YES/NO/UNKNOWN, with required explanatory notes. Technician repairability and pathway decisions are deferred. Notes/unknown answers do not claim an inspection occurred.

Subjects use UUID PKs, a unique opaque `EWC-` code with 128 random bits, kind `item`, positive bigint version and UTC `timestamptz`. Item/owner/category FKs restrict deletion. Identifiers are optional and deliberately nonunique. Subtype/category compatibility, fixed vocabulary, positive versions, photo size/MIME, aggregate-version uniqueness and one confirmed custody row are protected by constraints/indexes. Lists and history paginate; lists eager-load category/subject and order by registration time with an ID tie-break.

Registration creates revision 1 and `item_registered`, a user party and initial custody, a completed command result and pending in-app outbox reference in one transaction. No notification UI/worker or delivery is claimed; pending references are retained for the later notification phase. Commands retain only a keyed payload digest and safe subject reference, not raw form data. Actor/action/key uniqueness plus actor-row locking serialize retries. Same payload returns the first authorized record; a different payload conflicts. A keyed identifier advisory lock serializes duplicate comparison without asserting serial uniqueness.

Edits append a new observation/event and increment subject version; data status is the current projection. Only owner Consumer edits while `processing_state=registered` are allowed. Locked account/membership/session eligibility, ownership, state and expected version are rechecked. Initial identity, category, model, optional identifier and ownership are not editable. Ordinary PostgreSQL UPDATE/DELETE of events/observations and destructive identity changes fail through triggers. These are persistence guards, not cryptographic immutability or proof against a database owner disabling triggers. Later correction/redaction/key rotation needs a reviewed forward migration/maintenance procedure. Destructive migration rollback is intentionally refused; existing data was never reset.

## Privacy, uploads and authorization

Laravel encryption protects optional identifiers at rest. HMAC-SHA256 compares trimmed, case-normalized serials with whitespace/hyphens removed; the application encryption key is used with separate identifier/command contexts and recorded version `app-key-context-1`. Keep that private key backed up. Rotation requires controlled ciphertext rewrapping and comparison/retry-hash compatibility; an ordinary new key would break stored identifiers/retries. No rotation service or new credentials were introduced.

Possible-duplicate feedback reveals only that a known identifier matched a record. It exposes no matched owner, model, ID, identifier or private link; both registrations are retained. Identifiers are never the primary/public identity. Event payloads contain observation reference/question version/duplicate flag, not raw serials, notes or contacts. Detail shows whether a private identifier exists, without rendering its plaintext. Self-report text/model/feedback are escaped. Private identifiers/photos are excluded from flashed input; safe values and valid retry keys are retained, and array-shaped invalid input cannot break rendering.

Every device route requires authentication, current account/session and Consumer role. Queries filter by owner and item policies recheck scope; actions repeat checks under locks. Other owners get 404. All staff roles get 403 until operational authorization exists, including label/lookup/photo routes. CSRF remains active. Authentication's existing generic recovery, throttling and feedback/security tests remain intact. Feedback-summary rendering is shared with access pages to reuse the existing component without duplicate live regions.

Photos accept content/MIME/extension-validated JPEG/PNG up to 5 MiB. A 16-megapixel resource guard also limits decoded allocation. GD re-encodes to PNG without embedded metadata; executable/SVG/HTML payloads and misleading extensions are rejected, including active content appended to a valid image. Random storage keys, checksum/size/MIME and private attachment/event linkage are recorded. The dedicated disk `storage/app/device-evidence` is outside `public/` and the generic signed local disk; `serve=false`. Retrieval rechecks item policy, attachment-to-item link and redaction, with private/no-store, safe PNG disposition and nosniff headers. No symlink is required. Storage failure rolls back all database state; a database failure after writing the blob deletes it. Forced failures are tested. Abrupt process termination and malware scanning are not claimed as solved by this compensation; a later controlled reconciliation procedure should review unreferenced private blobs.

## Dependencies and local setup

- `endroid/qr-code` 6.0.9 (`~6.0.9`) generates actual PNG labels, using `bacon/bacon-qr-code` 3.1.1 and `dasprid/enum` 1.0.7. The 6.0 line supports PHP 8.3; 6.1 raises that requirement. This does not lower the existing lockfile's Symfony PHP 8.4.1 floor. Actual runtime is PHP 8.5.4. See the [generator's primary repository](https://github.com/endroid/qr-code).
- `khanamiryan/qrcode-detector-decoder` 2.0.3 is development-only, independently decoding served/downloaded labels in tests. See the [decoder repository](https://github.com/khanamiryan/php-qrcode-detector-decoder).
- Existing GD is now an explicit Composer requirement for image re-encoding and QR PNGs. No OS package was installed. Existing dependencies were not broadly upgraded; npm dependencies/lockfile are unchanged.

QR contains only the opaque lookup URL, never model/serial/owner/contact data or bearer credentials. Decoded GET requires the same current account/ownership checks; manual entry is available without camera permission. Detail and print include a readable EWC code; download supplies the real QR PNG. Printing/download/replacement does not generate a new subject.

Inspection found native PHP's `upload_max_filesize=2M`, below the form's supported limit. New `composer run serve` sets 5M upload/8M POST limits on its own native PHP process and delegates to Laravel's router via a tiny working-directory adapter; `composer run dev` uses this command. No system PHP configuration changes. The standard development port stays 8000. README explains setup, private key/storage handling and usage.

## Actual verification

- `composer validate --strict` — passed with the final dependency/server configuration.
- `composer check-platform-reqs` — passed, including GD, `pdo_pgsql` and PHP 8.5.4.
- `php artisan migrate --no-interaction` and `php artisan migrate --env=testing --no-interaction` — passed; both histories show all five migrations ran.
- `composer test` — **64 tests passed, 941 assertions**. Includes all 47 prior tests plus 17 Phase 2 tests: all seven category/subtypes, required/unknown/known values, excluded inputs/injection, unique identities, QR decoding/manual/replacement/download, retries/conflicts, private duplicate warnings, all route role/owner boundaries, revisions/stale/receipt guards, trigger protection, photo validation/private retrieval/metadata stripping/rollback/cleanup, pagination, safe output/feedback/error associations and malformed array input.
- `php vendor/bin/pint --test` — passed. `npm run build` — passed with Vite 8.3.2; compiled assets remain ignored.
- Live native HTTP smoke — login/notice returned 200; eight list/create/detail/edit/lookup/label/QR/photo guest routes redirected to login; missing-CSRF registration returned 419; production CSS/JS returned 200 and included focus/print wiring. The new upload-limit/router command was exercised on a free temporary port. No account/device writes were made by this smoke check. Authenticated mutation/lookup/label/photo behavior is verified by the PostgreSQL HTTP feature tests, not by a browser claim.
- Owned temporary servers were stopped and listeners closed. Existing services were preserved. Temporary smoke helper is excluded from Git.
- Before migration, development had two existing accounts and no device tables. After verification it still has two accounts and zero subjects/items/photos/commands. Live role/database owner is `ewaste_app` on `ewaste_development`. Independent guarded test inspection confirmed `ewaste_testing`, owner/role `ewaste_app`, six category reference rows and zero accounts/domain fixtures across all new tables. Test isolation safeguards and PostgreSQL-only fixtures were preserved; no reset was used.
- Staged review — 44 Phase 2 paths inspected; configured private values/private keys absent; dependencies, generated builds, runtime files and temporary evidence excluded; selected reviewed exports match their original on-disk bytes. Test guards and npm lockfile are unchanged.
- Browser tool inventory returned no apps/browsers. No rendered layout, print dialog, actual keyboard/focus or screen-reader pass is claimed.

Initial failures are not counted as passes: JSONB key ordering, a validation fixture exceeding the actual throttle, a public-file denial expecting 404 instead of Laravel's 403, and selecting an earlier random UUID in a fixture were corrected. An initial development-router smoke found its static-file return value was not propagated; the adapter now returns Laravel's result, and the final smoke passed. The final results above are the actual successful runs.

## Manual acceptance required before phase closure

Start `composer run serve` after a build, or `composer run dev`. Use synthetic accounts and images; never share private credentials, identifiers, photos or logs.

1. At desktop and 360px, inspect My devices empty/populated/paginated states, category selection, form, detail, revision history and print page. Confirm readable contrast, wrapping and no page overflow.
2. With only the keyboard, use navigation, category picker, every input/select/upload, submit, error-summary links and detail actions. Confirm visible focus and useful summary focus after failure; verify field-error associations and success/information announcements with a screen reader. Feedback must remain visible and colour must not be its only cue.
3. Register one supported category/subtype at a time. Unknown model/serial remain valid; every required self-report can explicitly be Unknown. Test a valid JPEG and PNG, including a photo larger than 2 MiB but within 5 MiB, an oversized/invalid file and safe field preservation/reselection after errors.
4. Verify registration success reaches detail and label, repeat the same form to confirm one identity, and try a second synthetic record with the same known serial to inspect the privacy-safe warning. No matched owner/device information should be visible.
5. Edit self-report and confirm old answers remain in history and success feedback appears. Submit two tabs with an old version; the second must show a conflict and retain committed history. Identity/ownership cannot be edited.
6. Print/download a label; scan it with an available external decoder or enter its full manual code. Confirm the correct owned detail, unchanged code on reprinting and readable printed code. Camera scanning within the application is not implemented.
7. Use a second Consumer and each staff role to attempt copied detail/edit/lookup/print/QR/photo URLs. Confirm denial and no private content. Check guests return to login and suspended/stale sessions lose access.

No unresolved automated/runtime blocker remains. Manual acceptance is pending; Phase 2 is not yet closed. Real inbox delivery remains unconfigured/unverified as in Phase 1. The passing implementation commit is `Add device registration`; no merge, push or next-phase feature is authorized.
