# Phase 1 — Authentication and Role Access

Date: 4 October 2026 (Africa/Nairobi). Branch: `phase-1-authentication`, based on Phase 0 commit `494e939`.

Status: implemented; automated/runtime verification passed. Desktop, 360px visual and keyboard checks remain unverified because no browser is available to the computer-use tool. No Phase 2 work, merge or push is included.

## Scope and references

Reviewed the supplied originals, without changing their content:

- Deliverable 2: §3.1 roles; FR01/FR02 access and authorization; FR33 registration notice acknowledgment; NF01/NF02 security; NF08 accessibility; A6 synthetic data/retention; §10.3 local recovery delivery; AT01 and the acknowledgment subset of AT10.
- Deliverable 3: US01, UC01, S01, AT01; §4.1 UUID/UTC conventions; §4.5 users, memberships and notice acknowledgments; §5.3–5.4 authentication interfaces/security; §7.6 responsive/form guidance. Later organization/facility authorization and privacy-request processing are deferred.
- Reviewed Stitch folder `docs/design/stitch_e_waste_connect_nairobi_prototype/`: S01 `code.html`/`screen.png` and `civic_stewardship_traceability/DESIGN.md`. The design index identifies the tracked references. All other supplied screens remain intact locally and ignored for this phase.

Implemented Consumer registration, email/password login, POST logout, recovery request/reset, account/session eligibility checks and minimal Consumer, Collector, Processor and Administrator landing pages. No devices, collection requests, custody, operators, processing, routing, reports or privacy-request endpoints were added.

D2 controls scope, D3 controls behavior, and Stitch controls presentation. There is no blocking access-requirement contradiction. Generated Stitch national-ID/passkey login, self-selected staff capacity, IPRS/regulator connections, ledger telemetry, certification and invented contacts conflict with the agreed scope or evidence. They are omitted while retaining its green palette, header, navigation, form cards and guidance sidebar. Forms use actual routes and errors instead of a diagnostic simulator. Local CSS/system fonts replace remote Tailwind, font, icon and image imports.

## Implementation and security decisions

- Laravel session authentication, hashing, password broker and reset notification are retained. No package or frontend stack was added; dependency lockfiles are unchanged.
- Registration normalizes email, validates optional display name, email, 12–128-character password/confirmation and current notice acknowledgment. Privileged role/status/membership/session-version inputs are prohibited; only validated fields reach the action. The server creates an active Consumer membership and acknowledgment in one transaction. Case-insensitive database uniqueness protects direct/concurrent inserts; duplicate registration errors remain safe.
- Argon2id is supported by the installed PHP runtime and configured through Laravel: 64 MiB, four iterations, one thread, algorithm verification and rehash on login. It checks the full accepted password, including Unicode and suffixes beyond bcrypt's input limit. Authentication tests lower work costs only in their test application; no private environment or production cost was reduced. Existing account tables were empty before migration, so no existing password hashes required an algorithm transition.
- Login rotates the session and binds `auth_session_version` to the account's `session_version`. Five failed attempts per normalized email/IP pair block further login until the 900-second window expires; success clears prior failures. Unknown, invalid and ineligible accounts receive the same credential error. Invalid fields are validated before credential attempts. No persistent “remember me” capability is offered.
- Protected routes reload account/membership eligibility and require an active account, active membership and matching session version before role authorization. Suspended/redacted accounts, inactive membership and missing/stale session bindings invalidate the session. The four-role enum and database CHECK match; cross-role access returns a safe 403. POST logout invalidates session data and rotates the CSRF token; GET logout is rejected.
- Recovery requests return the same redirect/message for known, unknown, ineligible and broker-throttled email addresses. Additional IP-based request/reset limits reduce abuse. Transport failure returns the same safe response and logs no credential/token or email. This is response consistency, not a claim of constant network/mail timing.
- Laravel stores token hashes, expires links after 60 minutes and suppresses new issuance within 60 seconds. A later link supersedes an older one. Issuance, login and reset lock the same account row. Reset validates/consumes the broker token and updates password, account version, session version and remember token in one transaction. No automatic login follows reset. All pre-reset sessions, including file-store sessions, fail on their next protected request; changing the password alone is not the revocation mechanism.
- CSRF guards every browser command. Passwords and recovery tokens are excluded from flashed input; sensitive action parameters are marked for trace redaction. Reset screens use a no-referrer policy and same-origin assets. Security logs record safe outcomes/opaque account IDs. Local recovery-message logs necessarily contain a private link; they are excluded from Git and ordinary HTTP responses.
- Notice `2026-10-04.1` is versioned project content in `resources/views/privacy/notices/2026-10-04-1.blade.php`. Registration retains version/time with a unique user/version constraint. Changing the notice requires a new content file and version, not editing the old notice in place. No automated retention or privacy-request processing is claimed.

## Migration and configuration

Before writes, both live connections independently confirmed PostgreSQL 16.15, cluster `16/main`, port 5432, role/owner `ewaste_app`, and separate `ewaste_development`/`ewaste_testing` databases. The role is non-superuser with no create-role/create-database privileges. Users, sessions and recovery-token tables were empty in both databases.

Migration `2026_10_04_000001_add_authentication_foundation` ran successfully on both dedicated databases. It preserves foundation rows and account/session associations while mapping integer account IDs to UUIDs; renames name/password to D3 display_name/password_hash; adds active/suspended/redacted status, positive account/session versions, UUID memberships and notice acknowledgments with FKs/uniqueness/CHECK constraints and UTC-capable account timestamps. Framework session/token/job/migration conventions remain. Existing framework migrations are unchanged. UUID identifiers cannot safely be reverted to old integer IDs: rollback deliberately refuses and requires a reviewed forward migration. No database was reset or seeded.

One global membership per account is sufficient for Phase 1's landing-page authorization. Organization/place tables, membership scope and operational permissions are deliberately deferred; staff landing pages grant no facility access. The command never promotes or overwrites existing users. PostgreSQL connection timezone is now explicitly UTC: verification exposed inherited `Africa/Nairobi` database time against Laravel's UTC clock. No cluster-wide setting or package was changed. Safe environment examples document Argon2id costs; credentials, keys and existing private environment files were preserved.

## Actual verification

- `composer validate --strict` — passed.
- `composer check-platform-reqs` — passed, including PHP 8.5.4 and `pdo_pgsql`; PHP's supported algorithms include Argon2id.
- `php vendor/bin/pint --test` — passed after formatting changes.
- `npm run build` — passed, Vite 8.3.2; generated assets remain ignored.
- `php artisan migrate --no-interaction` and `php artisan migrate --env=testing --no-interaction` — passed; both migration-status checks show all four migrations ran.
- `composer test` — **41 tests passed, 367 assertions**, no failures/errors in the final run. Includes PostgreSQL integration, registration/validation/hash/notice, injection prevention, every role boundary, login/throttle/logout/CSRF, suspended/redacted/inactive accounts, generic recovery, expiry/invalid/reused/superseded tokens, reset validation, session revocation and local CLI safety/provisioning.
- A dedicated file-session test writes two authenticated session files to a private temporary directory, positively replays a pre-reset session, resets through HTTP, then proves both old cookies are rejected and their old files removed. Temporary session data is cleaned up.
- Local log-mail test uses a private temporary log, verifies the generated link against the broker, and confirms the token is absent from the ordinary recovery response. No external mail is sent; temporary messages are cleaned up without printing their contents.
- Unsafe development/production database probes — unchanged test guard rejected both configurations before the database transaction hook or test body. PHPUnit's pinned configuration and `tests/TestCase.php` safeguards were not weakened. Tests use PostgreSQL transactions, never SQLite or database resets.
- Real HTTP smoke — login, registration, recovery request, notice and reset pages returned 200; inputs had associated labels; built same-origin CSS/JS returned 200; all four homes redirected guests to login; a POST without CSRF returned 419. Temporary Laravel server stopped and port 8000 had no listener afterward. These checks do not prove visual layout or keyboard usability.
- Browser checks — **unverified**. Computer-use inventory returned no browsers/apps; creating an in-app browser failed with `Browser is not available: iab`. Desktop/360px screenshots, keyboard/focus order and browser contrast/accessibility inspection were not run. They remain a manual handoff item, not a reported pass.
- Staged review — 53 paths inspected, configured private values/private keys absent, and no dependencies, builds, runtime files or temporary evidence staged. Selected supplied references match their on-disk bytes. Application/documentation diff checks passed; the full whitespace check flags seven intentional Markdown hard-break lines in the supplied D2/D3 headers. Their original bytes are preserved rather than editing authoritative references.

Earlier failed test iterations were corrected, not counted as passes: timestamp verification exposed the connection timezone mismatch; suspension fixtures used mass assignment of deliberately protected fields; the file-session fixture required the correct JSON serialization and independent request state. The final results above reflect the corrected implementation/fixtures. Post-test account/membership/notice/token counts remained zero in the dedicated databases.

## Handoff and limitations

From the project root, build assets and start `php artisan serve --host=127.0.0.1` (or `composer run dev`). Register a synthetic Consumer at `/register`, acknowledge the notice, then sign out/sign in. For staff, an authorized local operator runs `php artisan app:provision-staff collector`, `processor` or `administrator`; enter the email/display name and private password at interactive prompts. `APP_ENV=local` is required. No default password, credential argument, unauthenticated provisioning route or staff-management UI exists. Test each role's own home and cross-role denial.

Request recovery at `/forgot-password`. With local `MAIL_MAILER=log`, open `storage/logs/laravel.log` privately in the local editor and use the link; do not share its contents or URL. `APP_URL` must match the local browser address. After reset, sign in with the new password and verify another previously authenticated browser must sign in again. Production email/TLS are not configured; later deployment requires a reviewed mail transport, HTTPS/secure cookies and debug disabled.

Before relying on UI acceptance, manually inspect login/register/recovery/reset/notice/error/role screens at desktop and 360px, keyboard-only navigation, visible focus, error associations and overflow. Accessibility markup/responsive CSS are implemented, but no browser or full conformance/usability claim is made.

No unresolved package, database, migration, automated-test or HTTP blocker remains. Browser acceptance is the explicit verification limitation. The reviewed source/references are committed locally as `Add authentication`; the full unused export stays preserved and ignored. No merge, remote change, push or next-phase feature is authorized by this report.
