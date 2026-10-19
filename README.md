# Smart E-Waste Collection, Tracking and Recycling Management Platform

University final-year project for Kenya, with Nairobi County as the case study. The native Laravel/PostgreSQL application includes account access, owned devices/standalone parts, collection requests, directory/review, allocation, simulated routing, physical receipts, acknowledged custody handovers, inspection/pathway decisions, bounded media inventory/assurance, and refurbishment/testing with acknowledged owner returns. See the phase reports in `docs/` for actual verification, scope and operational limitations. Detailed integrated acceptance remains deferred as recorded in [the checklist](docs/deferred-acceptance-checks.md); this is not deployment or full-validation approval.

## Prerequisites

- Ubuntu with Laravel 13 and a supported PHP version. Laravel 13 supports PHP 8.3–8.5; this lockfile resolves on PHP 8.5 and requires at least PHP 8.4.1 through its Symfony dependencies. Use PHP 8.5 for this checkout. This environment has Ubuntu 26.04.1 LTS and PHP 8.5.4 CLI.
- Composer 2 (verified 2.9.5).
- Laravel's required PHP extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML; additionally **pdo_pgsql** and **GD** for this application. GD re-encodes private photos and generates real PNG QR labels. These extensions are verified loaded in PHP 8.5.4 CLI; do not reinstall them in this environment. On another Ubuntu machine, install the matching GD package (for example `php8.5-gd`) only if it is missing.
- PostgreSQL **16**, using its current supported minor release, and `psql`. Verified installation: PostgreSQL 16.15, cluster `16/main`, online on port `5432`.
- Node.js >= 22.12/npm for the locked Vite and development tooling (verified runtime: Node v26.8.1, npm 11.19.0; Vite 8.3.2).

Sources: [Laravel 13 requirements](https://laravel.com/framework/docs/deployment), [supported PHP versions](https://laravel.com/framework/docs/releases), [PostgreSQL Ubuntu packages](https://www.postgresql.org/download/linux/ubuntu/), [PostgreSQL version policy](https://www.postgresql.org/support/versioning/).

## One-time administrator preparation

For a fresh Ubuntu machine, run these targeted commands in a terminal that can authenticate to sudo. This checkout already has the PHP PostgreSQL extension and PostgreSQL 16.15 installed; do not reinstall them. Use the official PGDG repository when the Ubuntu repositories do not provide PostgreSQL 16. These commands do not perform a broad system upgrade:

```bash
sudo apt update
sudo apt install postgresql-common php8.5-pgsql
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh
sudo apt update
sudo apt install postgresql-16 postgresql-client-16
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
psql --version
pg_lsclusters
```

Use the PHP extension package matching your CLI version on other machines. Keep existing clusters and services intact. Select the PostgreSQL 16 cluster and its port from `pg_lsclusters`; the local examples and test guard use `127.0.0.1:5432`. If another cluster occupies that port, choose the PostgreSQL 16 port and update both environment files, the forced test port in `phpunit.xml`, and its guard in `tests/TestCase.php`. Do not change unrelated clusters.

Connect to that cluster as its administrator (replace `5432` if needed):

```bash
sudo -u postgres psql --port=5432 --set=ON_ERROR_STOP=1 postgres
```

Inspect the proposed names before creating anything:

```sql
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
FROM pg_roles WHERE rolname = 'ewaste_app';
SELECT datname, pg_get_userbyid(datdba) AS owner
FROM pg_database WHERE datname IN ('ewaste_development', 'ewaste_testing');
```

If any already exist, stop creation and inspect ownership, permissions, schemas and tables (`\connect ewaste_development`, `\dn+`, `\dt *.*`, and likewise for tests). Reuse only confirmed dedicated project databases with the correct owner; never drop, reset, or overwrite existing data. Existing roles must have all administrative flags disabled.

**Only when these names are absent**, create the dedicated role and databases:

```sql
CREATE ROLE ewaste_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
\password ewaste_app
CREATE DATABASE ewaste_development OWNER ewaste_app;
CREATE DATABASE ewaste_testing OWNER ewaste_app;
REVOKE ALL ON DATABASE ewaste_development FROM PUBLIC;
REVOKE ALL ON DATABASE ewaste_testing FROM PUBLIC;
\connect ewaste_development
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
\connect ewaste_testing
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
\quit
```

Choose a private password at the prompt, then set the same `DB_PASSWORD` in `.env` and `.env.testing`. Existing local environment files must match the password actually assigned to `ewaste_app`; do not overwrite them with example placeholders. Do not put passwords in shell commands, shared output, or Git. Database ownership gives `ewaste_app` the schema/DDL permissions Laravel migrations need within these two databases, without server-wide administrative privileges.


### Repair a local password mismatch

If both project connections fail with `password authentication failed for user "ewaste_app"`, the driver/server are reachable but the configured credentials are not accepted. Open the administrator session above and first inspect the dedicated role and both database owners. The role must have login enabled, administrative flags disabled, and own both project databases. If those are correct, use the private prompt:

```text
\password ewaste_app
\quit
```

Save that password in `DB_PASSWORD` in both `.env` and `.env.testing` using your editor. Do not paste it into chat or shell commands. Clear configuration with `php artisan config:clear`, then verify both live connections and ownership before migrations. Do not reset databases or change unrelated roles.

## Install and configure

From this repository root, install the locked dependencies:

```bash
composer install
npm ci
```

On a fresh checkout, copy the examples **only if local environment files do not already exist**:

```bash
cp .env.example .env
cp .env.testing.example .env.testing
chmod 600 .env .env.testing
```

Edit the local files: use `pgsql`, `ewaste_app`, your private password, and the PostgreSQL 16 cluster's host/port. Keep `.env` on `ewaste_development` and `.env.testing` on `ewaste_testing`; leave `DB_URL` empty. Generate keys and clear configuration:

```bash
php artisan key:generate
php artisan key:generate --env=testing
php artisan config:clear
```

The current local environment files and keys have already been prepared. Do not regenerate keys unnecessarily. Safe examples contain no keys or real credentials. Session/cache use files locally, and queues run synchronously; no worker, Redis, virtual host, TLS, or application system service is needed.

After administrator preparation, confirm ownership and contents, then apply pending migrations on the separate dedicated databases:

```bash
php artisan migrate
php artisan migrate --env=testing
```

Phase 1 adds account UUIDs, fixed role memberships, notice acknowledgments and session revocation fields. Phase 2 adds the device identity/self-report/private-evidence foundation and six supported category reference rows; no sample devices or accounts are seeded. Both domain migrations intentionally refuse destructive rollback: use a reviewed forward migration. Do not run `migrate:fresh`, `db:wipe`, or database resets. The PostgreSQL connection uses UTC for the application's timestamps. PHP must support Argon2id password hashing (`php -r 'echo implode(", ", password_algos());'`).

## Develop, build, and test

Run the Laravel development server and Vite together:

```bash
composer run dev
```

Open <http://127.0.0.1:8000>; Ctrl+C stops both processes. Alternatively, use `composer run serve` and `npm run dev` in separate terminals. For compiled assets, build once and run just the Laravel server:

```bash
npm run build
composer run serve
```

`composer run serve` delegates routing to Laravel's development router and explicitly sets `upload_max_filesize=5M` and `post_max_size=8M`. The installed PHP default is only 2 MiB; an ordinary `php artisan serve` may therefore reject otherwise supported photos. No system PHP setting is changed. Stop an existing local server normally before starting another on port 8000.

Required validation commands:

```bash
composer validate --strict
composer check-platform-reqs
php vendor/bin/pint --test
npm run build
php artisan migrate
php artisan migrate --env=testing
composer test
```

`composer test` clears cached configuration before PHPUnit. Tests force the dedicated local PostgreSQL connection, database name and non-superuser role; inherited `DB_URL` and database selection cannot redirect them to development/production. The shared test base also rejects cached configuration and a development database matching the test database before database traits execute. Direct PHPUnit commands must use the repository's `phpunit.xml` and an uncached configuration. Do not run parallel tests against the shared test database.

The integration test checks the actual server/database/role, PostgreSQL major version 16, UTC, absence of administrative role privileges, database separation, and transactional read/write access. Authentication tests cover registration, validation, notice acknowledgment, all four role boundaries, throttling, CSRF, suspension, recovery, logout and revocation of persisted file sessions. Fixtures roll back; no database is reset. Tests use lower Argon2id costs only inside the test application; local accounts use 64 MiB/four iterations by default.

After starting the development server, an HTTP smoke check is:

```bash
curl --fail http://127.0.0.1:8000/login
```

Stop the server when finished. Dependencies and builds remain local; commit `composer.lock` and `package-lock.json` along with source after all checks pass.

## Exercise account access

Open `/register` to create a synthetic Consumer account. Email is required, display name is optional, and passwords use 12–128 characters with confirmation. Read and acknowledge the versioned privacy notice. Public registration cannot select staff roles or account status. Sign in at `/login`; POST forms provide logout. Five failed attempts for a normalized email/IP pair block further attempts until the 15-minute window expires.

An authorized local project operator can provision staff through hidden password prompts:

```bash
php artisan app:provision-staff collector
php artisan app:provision-staff processor
php artisan app:provision-staff administrator
```

The command requires `APP_ENV=local`, validates the email/password, refuses existing emails, and never changes an existing account. Use synthetic account information and choose private passwords; no default credentials are shipped. Use each account at `/login`, then check `/consumer`, `/collector`, `/processor` or `/administrator`. Other role pages deny access. Bootstrap Collector accounts remain unscoped until an Administrator grants the bounded Phase 5 operator access below; no operator link is inferred. Processor/facility tools remain deferred.

### Local password recovery

The effective local mailer is `log`. This is working local delivery: messages are written to the application log, not an email inbox. No SMTP provider is configured. Laravel documents this behavior in its [local mail guidance](https://laravel.com/framework/docs/13.x/mail#mail-and-local-development). Tests use fake notifications or a private temporary log; they never send external mail.

To retrieve a recovery message privately:

1. Set `APP_URL` in your private `.env` to the address used by your browser (for example `http://127.0.0.1:8000`). Keep `MAIL_MAILER=log`, then run `php artisan config:clear` and restart your local server if configuration changed.
2. Submit your account email at `/forgot-password`. The acknowledgment is always “If an eligible account exists for that email, password reset instructions will be sent.” It does not confirm account existence or email delivery.
3. Open `/var/www/html/E-Waste-Recycling-Project/storage/logs/laravel.log` directly in your local editor. Search for `Reset Password Notification` and select the newest message matching your request time and recipient. No message is generated for an unknown/ineligible email or a throttled request; wait at least 60 seconds before requesting another link (the IP limit is five requests per minute).
4. Use the complete link from the plain-text part of that message privately in your browser. If you only see MIME-encoded HTML, quoted-printable `=3D` means `=`, a terminal `=` joins the next line, and `&amp;` means `&`; the full link must not contain soft line breaks. Do not paste the message, password, token or URL into chat, reports or shared terminals.
5. Choose and confirm a new password, then sign in from the success page. A previously signed-in browser must sign in again on its next protected request.

Keep logs outside `public/`, restrict local access, and remove obsolete evaluation recovery messages privately when no longer needed. Do not delete unrelated log records. Acknowledgments, validation errors and successful actions remain visible on their destination pages; failed submissions focus a linked error summary. No feedback automatically disappears.

Links expire after 60 minutes, can be used once, and issuing another link supersedes the earlier one (requests within 60 seconds are suppressed). Successful reset requires a fresh sign-in and rejects every previously authenticated session on its next protected request. Account suspension, redaction or inactive membership also rejects existing sessions. Known, unknown and ineligible email recovery requests receive the same message.

### Optional real inbox delivery

SMTP is supported by the installed Laravel mailer; no additional package is needed for this transport. See [Laravel mail configuration](https://laravel.com/framework/docs/13.x/mail#configuration). The commented SMTP template in `.env.example` contains placeholders only and leaves `MAIL_MAILER=log` active.

Obtain a provider's SMTP host, port, TLS scheme, username/secret and verified sender address privately. Only when those values are available, edit your existing `.env`: set `MAIL_MAILER=smtp`, fill `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`, and ensure an inherited `MAIL_URL` is empty unless you deliberately use a provider DSN. Use the provider's settings: typically `smtp` with STARTTLS on 587 or `smtps` with implicit TLS on 465. Do not disable certificate verification or paste credentials into commands, Git or chat. Keep `.env.testing` on array delivery.

Run `php artisan config:clear`, restart the local server and manually request recovery for an account whose inbox you control. Check the received message (including spam/provider delivery events) and complete reset before reporting inbox delivery as passed. This work did not configure a provider or send external mail: **real inbox delivery remains unverified**. The generic acknowledgment is never a delivery receipt. Retain log delivery until the private provider configuration is ready.

For any later deployment, configure HTTPS, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false` and a reviewed mail transport; this phase configures local evaluation only. Do not change the frozen notice text in place: add a new versioned notice file and update `App\Support\PrivacyNotice` when its content changes.

## Exercise owned devices

After applying both dedicated migrations and building assets, start `composer run serve` (or `composer run dev`) and sign in as a Consumer. Open **My devices** from `/consumer`, or visit `/items`.

1. Select a supported category at `/items/create`, choose Router/Keyboard for peripherals, then **Load category questions** before entering answers. Changing category reloads the form and discards unsaved answers.
2. Optionally provide make/model and select **Serial number** or **IMEI**; leave unknown identifiers blank. Serials accept ASCII letters/digits, whitespace and hyphens (maximum 100 raw characters). IMEI must normalize to 15 digits with a valid check digit; formatting spaces/hyphens are allowed. This checks format, not allocation or ownership. One identifier per device is supported. Required functional, physical-damage, data-bearing and safety answers offer explicit Unknown. Explain your observations or uncertainty in the notes. Do not upload device contents or personal information.
3. Optionally select a JPEG/PNG up to 5 MiB and 16 megapixels. Photos are re-encoded to PNG to remove embedded metadata and stored under `storage/app/device-evidence/` outside the generic local disk and `public/`. Only the scoped authenticated photo route serves them; no storage symlink is required. Failed forms require selecting a photo again and re-entering private identifiers.
4. Register once; repeat submission of the same form/payload returns the same device. A changed payload using that reference produces a conflict. An already registered serial/IMEI blocks a genuinely new registration with “This serial/IMEI is already registered. Please recheck the number entered.” No matching owner, code or private record is disclosed. Comparison is global across owners, categories, manufacturers, types and lifecycle states: trim, remove whitespace/hyphens, uppercase ASCII letters, preserve leading zeroes, then compare the existing keyed hash. Unknown identifiers never conflict. Numeric serials and IMEIs with the same normalized text also conflict.
5. On detail, use **Update self-report** before receipt. Every save retains earlier revisions; another tab with an old version is rejected. Identity, category, model, identifier and ownership are fixed through ordinary edits.
6. Use **Printable label** and **Print label**, or download the QR PNG. The readable EWC code is alongside the QR on detail/print pages. `/items` supports manual code entry. QR encodes only the authenticated lookup URL plus opaque code, not credentials or personal details; reprinting keeps the same code. Keep `APP_URL` and the browser address consistent.

Collector, Processor and Administrator roles have no device access in this phase; assignment/facility scope is not yet implemented. There are no collection, scheduling, camera-scanning or processing controls. Device history is registration and retained self-report only.

Back up the private `APP_KEY` with the database and private photos. It protects identifiers and context-separated comparison/command hashes (`app-key-context-1`). Do not regenerate it after storing records: reviewed key rotation must rewrap encrypted identifiers and maintain/recompute comparison hashes and retained retry hashes. No automated key rotation, redaction, malware scanning or general notification worker is claimed. Phase 5 delivers only explicitly supported new assignment events in-app; earlier pending outbox rows remain for a later notification increment.

The identifier revision passes development/testing migrations and required automated/runtime checks after the user-authorized cleanup of three repeated test registrations. The fourth device and all accounts were preserved. The user has confirmed duplicate rejection, IMEI validation and all remaining manual checks; Phase 2 is accepted for closure. Actual automated results and distinct user-performed acceptance are recorded in [the Phase 2 report](docs/phase-2-report.md#user-performed-acceptance-and-closure-review--5-october-2026). Real inbox delivery remains unconfigured/unverified.

### Exercise standalone parts

At `/items/create`, select **Standalone ICT part**, load its type choices, choose **Hard disk drive (HDD)**, **Solid-state drive (SSD)** or **Small ICT cooling fan**, then load the questions. Register one already separate unit. Unknown drive types, multi-drive enclosures, batteries, mains/industrial fans, arbitrary components and bulk inventory are outside this intake. Do not power, dismantle or inspect inside a part to answer the form.

Parts accept an optional serial only; the same global uniqueness, encryption and private re-entry policy apply. HDD/SSD data-bearing capability is always **Yes**, including empty, broken or reportedly erased media. It is displayed as text, not a consumer override; registration does not verify sanitization or approve reuse. A drive's storage facet uses its existing physical identity, with no extra label, custody or parent history.

A fan must be known to be one dedicated ICT cooling fan/blower, no more than **24 V DC** and **140 mm** across its housing (excluding free leads). Use existing knowledge or an accessible label; if its eligibility is unknown, stop rather than test or dismantle it. Ordinary motor-driver/tach/PWM circuitry is basic. Known additional control/network/storage modules block registration; eligible fans with unknown electronics can register but need review. Later self-report revisions may honestly record newly discovered uncertainty or exclusion, preserving earlier answers and the same identity. These are self-reports, not technician approval.

Owned list/detail, retained history, private photos, manual lookup and QR/printing work through the existing device routes. Parent/removal/storage/server-owned fields are rejected even when null; notes never create parent links. Safe form values remain after errors, while serials and photos need re-entry/reselection.

The forward migration `2026_10_06_000001_create_standalone_parts_intake` checks the known category/schema/identity protections and existing item classifications before changes. It backfills existing items as whole devices only, preserves legacy command hashes and creates no old storage or parent records. Use the existing `php artisan migrate` and `php artisan migrate --env=testing` setup commands on the verified dedicated databases; do not reset them or edit historical migrations. Unexpected state blocks migration for review; destructive rollback is intentionally refused. See [Phase 2A's migration and acceptance record](docs/phase-2a-report.md).

## Collection requests (Phase 3)

Apply the two new forward migrations on the verified separate databases using the existing migration commands. Migrations create no demo operator/point data. In APP_ENV=local, explicitly initialize the fictional study directory and boundary:

```bash
php artisan app:setup-collection-demo
```

This command requires a dedicated local PostgreSQL project database owned by non-elevated `ewaste_app`. It preserves existing configuration on repeats. It adds labelled synthetic all-ICT, whole-device-only and inactive points, with explicit HDD/SSD/fan capabilities only where configured; none establishes real facility certification or acceptance. The whole-device-only point cannot accept loose parts, and the inactive point is excluded. No compatible point produces a clear limitation instead of an automatic replacement. Deliberate point/capability edits are not restored by repeating setup.

`SYNTHETIC-STUDY-1.0` is a hand-defined rectangle: latitude −1.350000 to −1.200000, longitude 36.750000 to 36.900000, boundary included within 0.000001 degrees. It is **not the official Nairobi County boundary**. Use only synthetic information for evaluation. A new geometry needs a new version; no map/location permission is required.

From My devices, open **My requests → Request collection**. Complete Devices → Collection method → Location → Review, with positive estimated kg per selected item, a preferred date, private contact, pickup address/entrance instructions and a confirmed point, or a compatible drop-off point, and the required privacy acknowledgment. Submission produces **Awaiting review** and a reference; the date is not a booking and custody remains unchanged. Private drafts are encrypted in the server session, with stale-tab checks; do not put contact/address into query strings or browser storage.

### Pickup map and manual fallback

At Location, use **Load pickup map**, then click/tap or drag the pin to the entrance/meeting point. Keyboard users can focus the map and use arrows/+ or −, or the direction buttons, followed by **Use map centre**. The starting view does not select a point. On mobile, swiping the map scrolls the page; direction/zoom controls provide positioning without map panning. Press **Confirm pickup point**, which saves confirmation on the server and returns to Location, then complete the other fields and Continue. This confirms your selection, not service or a booking.

**Use my location** makes one explicit browser geolocation request per page load, requiring HTTPS or localhost. Reported accuracy is approximate; inspect/adjust the point rather than assuming GPS gives an exact entrance. Denial, unavailable location, timeout or unsupported/restricted browsers allow manual selection. No background tracking or repeated permission loop runs. Moving/replacing the point or changing the study area requires confirmation again. Back with the same items/method and errors in unrelated fields preserve a valid selection. Switching method or selection clears it. Out-of-area points remain blocked by the server.

**Advanced location entry** accepts decimal latitude/longitude (at most six decimal places) when JavaScript, tiles or browser location are unavailable. Open it, enter the point and press Confirm pickup point; Continue still uses server validation. You can use this fallback without loading tiles or granting permission. Address/landmark/entrance guidance remains required. Do not place private locations in URLs, logs or browser persistent storage.

Leaflet **1.9.4** is bundled locally by Vite; no geocoder, search service or private API key is introduced. Public settings in `config/maps.php` and `.env.example`:

```dotenv
PICKUP_MAP_ENABLED=true
PICKUP_MAP_TILE_URL="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
PICKUP_MAP_ATTRIBUTION_TEXT="© OpenStreetMap contributors"
PICKUP_MAP_ATTRIBUTION_URL=https://www.openstreetmap.org/copyright
```

Set `PICKUP_MAP_ENABLED=false` to keep manual entry available without map loading. After local configuration changes, use `php artisan config:clear` (or rebuild your deployment's config cache). Tile URL/attribution are public; never insert private keys. Switching provider requires checking its permission, attribution and privacy requirements and revising the notice where necessary. No live environment file was changed for this increment.

The default provider is OpenStreetMap Standard raster tiles. Its [official tile policy](https://operations.osmfoundation.org/policies/tiles/) requires attribution, identification and normal caching, and prohibits bulk/offline downloads. It is best-effort with no SLA. Browser requests keep the browser User-Agent/cache behavior; only tile images use `referrerPolicy: origin`, keeping private page paths out of their referrer. Verify this survives deployment headers/proxies before live use. Automated checks use local mock tiles, never OSM pan/zoom traffic. No cache-bypass, proxy, prefetch or offline feature is added.

The optional map shares IP/browser information, site origin and viewed tile areas with its provider; names, contact and address text are not sent. The [privacy notice](resources/views/privacy/notices/2026-10-06-2.blade.php) version `2026-10-06.2` explains this and the permission-free fallback; prior versions/acknowledgments are retained. Real tile delivery, device permission dialogs/accuracy and screen-reader/touch acceptance still require manual checks in [the report](docs/phase-3-report.md#pickup-location-enhancement--6-october-2026). Run `npm test` for the small dependency-free JavaScript tests in addition to the normal PHP suite, Pint and production build.

My requests/detail show owned items, current per-item preferences and actual history. Select outstanding unreceived items to cancel or change their preferred date with a reason. Only cancelled reservations release. Reload when a request/wizard version is stale; retries with the same successful command return its original result. Safety Yes/Unknown and unresolved fans carry review restrictions; later self-report revisions refresh pending eligibility/history. Unknown past function alone is not a hazard declaration. All outstanding requests still await human review; there is no dispatch, receipt, sanitization or request-notification delivery in this increment.

The [Phase 3 report](docs/phase-3-report.md) records preservation, tests, synthetic assumptions and remaining desktop/360px, keyboard and screen-reader acceptance. Existing log-based password recovery remains local; real inbox delivery and real collection-point acceptance remain unconfigured/unverified.

## Requirements and reviewed design

The actual authoritative Deliverables 2 and 3 are supplied in [`docs/requirements/`](docs/requirements/README.md); the reviewed Stitch export and handoff are in [`docs/design/`](docs/design/README.md). Deliverable 2 controls scope, Deliverable 3 controls behavior, and Stitch supplies visual presentation. The supplied originals are preserved.

Follow [`AGENTS.md`](AGENTS.md) for the agreed stack, scope and phase workflow. Review each phase's applicable requirements and screens before implementation. Phase 2 verification and user acceptance are complete. Phase 2A is implemented and user accepted. Phase 3 implements the separately authorized consumer request increment; browser acceptance and closure remain pending.

CR-PARTS-01 supplies the bounded [implementation specification](docs/parts-intake-design-report.md) and [written UI handoff](docs/design/parts-intake-handoff.md). Those documents preserve the reviewed design and its original verification history; [Phase 2A's report](docs/phase-2a-report.md) records implementation results. Batteries and processor-derived components remain excluded from this increment. Real collection-point acceptance is unverified.

### Identifier migration handoff

`2026_10_05_000001_enforce_unique_device_identifiers` checks for duplicate comparison keys under a PostgreSQL table lock before any schema changes. It refuses conflicting existing records rather than deleting, merging, altering identifiers or choosing a survivor. The three original development conflicts were confirmed by the user as repeated test registrations and removed through narrowly authorized, backed-up maintenance; the fourth device and all accounts remain unchanged. Both dedicated databases now have the unique index and type metadata. See [the Phase 2 report](docs/phase-2-report.md#authorized-test-registration-cleanup-and-final-verification--5-october-2026) for the safe cleanup counts and actual verification.

For future conflicts, stop and obtain explicit review/authorization rather than resetting a database or bypassing protections. Back up the database, application key and private evidence before approved maintenance. The private cleanup backup is retained under `storage/app/maintenance-backups/`, outside all served disks and ignored by Git, with restricted directories/files; its recovery instructions and key remain private. Restoration requires separately reviewed maintenance or an isolated recovery environment, because restoring duplicate records into a unique live namespace is not automatically valid. No public endpoint or reusable application cleanup command was added.

Nonconflicting old identifiers retain their ciphertext/hash and acquire `legacy_unspecified` type. The migration leaves retained historic events and command hashes untouched. A request replayed with its original fields/reference still resolves the original successful registration. Serial/IMEI type is immutable in ordinary edits. Do not regenerate `APP_KEY` or use a new hash context to bypass uniqueness.

[The original parts assessment and approved bounded scope](docs/device-parts-scope-change.md) preserve the distinction between standalone intake, excluded batteries and deferred processor-derived work.


## Administrator directory and request review (Phase 4)

Apply the forward migration on both dedicated databases without resetting them:

```bash
php artisan migrate
php artisan migrate --env=testing
```

If no active Administrator exists, run `php artisan app:create-initial-administrator` in your private local terminal. Enter a new email and a private password (at least 12 characters, including letters and numbers) at the hidden prompts. The command verifies the dedicated local database/role and creates only the first Administrator. It refuses to convert existing Consumer accounts or provision further staff. No Administrator is automatically seeded by migrations; no Collector/Processor membership workflow is added.

Sign in as that Administrator and open **Operators and places** or **Request review**. Record contact information privately, explicit category/subtype acceptance, hours, location, review reason and private JPEG/PNG/PDF evidence. Incomplete evidence remains pending. Active places require project-reviewed organizations and retained evidence; synthetic markers remain permanent. Project review is not regulator certification. Evidence downloads require Administrator access, remain outside public storage, and must be included with the existing application key in private backups. PDFs are download-only; no malware-scanning guarantee is made.

Request review records **Ready for later allocation** or an explained **Hold**, using current observations, service area and directory versions. Safety/unknown restrictions cannot be overridden. Directory changes require fresh review; existing reservations and custody remain until an authorized later transition or Consumer cancellation. No booking, assignment, physical collection or inbox delivery is created. Consumers see only safe status/guidance, without private staff notes or evidence. See the Phase 4 report for actual checks and manual acceptance steps.

### Nairobi references, duplicate rules and place locations

[Directory data sources](docs/directory-data-sources.md) distinguish verified Nairobi locality references (Westlands, Kilimani, Kasarani) from fictional study operators, contacts, capabilities and geometry. No real facility/contact/acceptance is seeded. Fresh demo setup uses Nairobi-labelled synthetic copy. Existing rows are retained; optionally preview `php artisan app:refresh-nairobi-demo-copy` locally. Only after reviewing, rerun with `--apply` to change eligible untouched, unreferenced seed names. Edited/operationally referenced records are skipped; no status, coordinates, hours, capabilities, ID or synthetic marker changes.

Apply the new forward migration before using the refined directory forms. The original development conflict was resolved through the user-authorized synthetic test-name correction, retaining all history and reservations; both dedicated migrations now pass (see the Phase 4 report). On any unmigrated installation, forms/new directory writes give a controlled **503** maintenance response; list/review/Consumer workflows remain available, and successful historical retries still resolve. The new forward migration checks existing duplicates **before** changing schema. Organization names are globally unique after PostgreSQL NFC/case/whitespace normalization; punctuation, accents and legal suffixes remain significant. Optional independently verified Kenya BRS registration numbers are unique in the `ke_brs` namespace after uppercase/whitespace normalization, retaining hyphens/slashes. They need an explicit Administrator verification declaration and new private evidence; no registry integration/licensing claim is made. Leave unavailable numbers blank. Inactive and suspended records participate in uniqueness.

Within an organization, normalized place name plus exact six-decimal coordinates identifies an existing site regardless of point/facility/depot type. Similar names, repeated address hints and sites within approximately **100 metres** require review of linked existing records and explicit acknowledgment of distinct records; co-location alone does not block legitimate separate sites. See the report for current preflight outcomes. Do not merge/delete/rename existing conflicts without user-directed reconciliation; rerun the preflight/migration after an authorized resolution.

Place forms share the existing Leaflet tile configuration. Choose **Load site map**, click/tap or drag the pin, or use keyboard arrows/direction buttons then **Use map centre**. **Use my location** is explicit and locates your current device, which may not be the facility; reported accuracy is approximate. Check the actual entrance. Surveyed/otherwise verified coordinates may be entered through labelled **Manual latitude / longitude entry**; typing coordinates alone does not make them more accurate.

Press **Confirm site entrance** before Save. Confirmation is bound to the coordinates, account, record, expected version and command reference. Changing location clears it; stale edits are rejected. Confirmation/error redirects retain permitted private values in encrypted server-session data for up to 30 minutes, never plaintext flashed input or browser storage. Upload files must be selected again. Manual entry works without JavaScript or provider access; map failure never substitutes a location. Capabilities remain ten exact visible choices grouped as whole devices, peripherals and standalone parts, in a responsive three/two/one-column grid.

The retained privacy notice is now version `2026-10-09.1`, describing optional Administrator site maps as well as pickup maps. Previous notices/acknowledgments remain. No geocoder, API key, dependency change or external mail is introduced. Live tile/device-permission, full screen-reader and touch acceptance remain manual checks; headless verification uses local synthetic tiles.

## Collector allocation and assignment handling (Phase 5)

Apply the forward migration on both dedicated databases using `php artisan migrate` and `php artisan migrate --env=testing`; never reset them. It preserves old account/history/request/identifier/custody data and backfills no organization links or assignments.

As Administrator, open **Collector access** (`/admin/collectors`) to create a Collector with private credentials or link/deactivate an existing Collector at one project-reviewed collector/both operator. Consumers cannot be promoted. Actual access changes revoke sessions and invalidate active assignments. An unchanged active operator link preserves them and records an audited check; the edit form displays/preselects current scope. Bootstrap staff remain unscoped until linked. Share credentials through your established private channel; no email is sent. Existing local log-based recovery remains available privately.

Open **Pickup allocation** (`/admin/assignments`). Only current Ready pickup jobs with valid reservations/observations, service-area/date/safety checks and explicit category/subtype capabilities can be offered. Choose a scoped Collector and an active reviewed facility/collection point under a processor/both operator; depots are excluded. The allocation page explains four prerequisites and concrete blockers, with named compatible choices and resolution links. Finish directory/capability and Collector access setup before Ready; every directory save requires fresh review. Reason fields are private. Withdraw or replace active assignments explicitly; replacement requires fresh Collector acknowledgment.

The named Collector opens **Offers and accepted work** (`/collector/assignments`) and accepts or declines. Offered jobs hide contact/address/coordinates; these become available only for still-eligible Accepted work. **Accepted — not scheduled** is not an appointment, dispatch, receipt or custody transfer. Changed or revoked eligibility removes that access. Consumer overview/request detail shows only owned safe assignment milestones. Cancellation releases only selected-item reservations; assignment changes never change custody. Drop-off behavior is unchanged.

Assignment-only in-app delivery uses the committed outbox. Run a private local one-shot when needed:

```bash
php artisan app:deliver-assignment-notices --limit=100
```

For automatic local delivery, run `php artisan schedule:work` in a separate terminal and stop it when finished. The application schedule runs this assignment-only command every minute; hosting can use Laravel's normal `schedule:run` setup. Do not run it against an unrelated connection. It processes only supported new assignment event types, deduplicates by outbox ID, and leaves historical pending non-assignment rows untouched. No external email is sent. Users open **Assignment notices** (`/assignment-notices`) and mark only their own notices read; linked pages reauthorize current access. A pending worker does not undo a committed assignment, and assignment screens remain authoritative.

Follow [the exact setup/review/offer/acceptance walkthrough](docs/phase-5-report.md#exact-walkthrough-for-one-pickup). Missing receiving setup is separate from safety restrictions; approval alone does not add capabilities. Stale forms provide role-safe reload links. Phase 5 has provisional acceptance for continued development; its [detailed acceptance checks remain deferred](docs/deferred-acceptance-checks.md). Phase 6 adds the bounded route planning described below. Phase 7 adds bounded first receipt and visit outcomes below; scheduling, later transport/handover and processing remain deferred. Real operator/collection-point acceptance and inbox delivery remain unverified/unconfigured.

## Route planning and comparison (Phase 6)

Apply the forward migration separately using `php artisan migrate` and `php artisan migrate --env=testing`; never reset either dedicated database. No vehicles, routes or assignments are backfilled. Existing records, history, reservations, custody and private keys/files are retained.

Administrator **Route planning** (`/admin/route-planning`) explains the setup order. Finish reviewed operator/depot/receiving directory setup and **Collector access** first; directory saves stale reviews and invalidate assignments. Then renew **Request review**, offer through **Pickup allocation**, and obtain named Collector acceptance. **Add vehicle declaration** provides the minimum code/operator/classification, scoped Collector mapping, capacity, tour limit and manually declared availability. There is no calendar or automatic fleet seed. Vehicle edits invalidate route approvals without changing request review or accepted assignment state.

Choose an operator and **Load operator inputs**; select its active reviewed depot, up to three available vehicle/Collector mappings and up to 50 assignments. Explicitly select **Haversine geometric simulation**, review constant speed and per-stop service assumptions, then **Freeze and compare routes**. The default speed is 20 km/h, service is 600 seconds, vehicle capacity starts at 100 kg (synthetic fleets may use 100/200/300 kg) and duration is 21,600 seconds. These are configurable study assumptions, not Nairobi traffic or verified vehicle specifications. Missing/ineligible jobs are excluded with reasons; no private development record is repaired to make a run pass.

Comparison retains the submission-order baseline and nearest-feasible/2-opt proposal on identical frozen inputs. Each tour returns to the depot, includes travel/service/return duration and respects mass and acknowledged Collector restrictions. A storage facet adds no second mass. Coverage differences suppress improvement percentages; computation/iteration limits are visible. There is no global-optimality, road navigation or fuel-savings claim.

**Approve proposed plan** revalidates current frozen dependencies. Active route claims exclusively reserve served assignments, vehicles and Collectors without scheduling a time slot. **Withdraw route approval**, explicit same-operator supersession or source invalidation releases those claims, preserving item reservations and custody. Old results/history remain; rerunning creates new results. Collector **Approved routes** (`/collector/routes`) exposes only current approved ordered stops and links to independently authorized accepted-job details. Approval is not a receipt, transport event, unloading or booked appointment.

**Show simulated map** is optional. It reuses installed Leaflet and `PICKUP_MAP_*` configuration, existing OpenStreetMap attribution/usage limitations and tile-provider privacy behavior. No provider call precedes that action, no names/contact/address text/request references are sent, and disabled/import/provider failure leaves the complete ordered list available. Lines are simulated straight-line legs. No road matrix service/API key, geocoder, paid dependency or external mail is introduced. Precise route snapshots are encrypted; locations are absent from URLs, logs and persistent browser storage.

Run tests against the guarded dedicated PostgreSQL testing database. To reproduce the synthetic routing outputs without modifying development data:

```bash
ROUTING_EVALUATION_OUTPUT=/tmp/ewaste-routing-evaluation php artisan test --filter=RoutingEvaluationTest
```

This executes 30 fixed paired cases, 240 one-factor sensitivity cases and ten full application-path benchmarks per size (10/25/50), using an owned temporary testing schema for benchmark records. Do not substitute another database or treat generated values as field observations. See [Phase 6's report](docs/phase-6-report.md) for actual results, limits and exact walkthrough. Manual acceptance remains open and [deferred checks](docs/deferred-acceptance-checks.md) must be completed before deployment/full validation.

## Bounded Nairobi demonstration setup

Public reference facts and simulated operations are separated in [directory data sources](docs/directory-data-sources.md). The new `app:setup-nairobi-demo` command requires `APP_ENV=local`, the dedicated public-schema `ewaste_development` connection on `127.0.0.1:5432`, non-superuser database owner `ewaste_app`, and an **existing active Administrator UUID**. It refuses production/testing/URL/alternate-schema connections and never creates an Administrator. The pre-existing eligible synthetic study operator/point and active study rectangle must already exist; changed/ineligible study setup is retained and reported, not repaired.

Preview first; the default is a read-only dry-run without password prompts:

To find an existing active Administrator UUID, open local `php artisan tinker` and run `DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('role', 'administrator')->where('active', true)->where('users.status', 'active')->pluck('users.id');`. This prints only account IDs; never dump account models or environment configuration. Exit Tinker before running setup.

```bash
php artisan app:setup-nairobi-demo ADMINISTRATOR_UUID
```

It proposes two new simulated operator references/receiving points, reuses the existing study collector operator, and adds three fictional Collectors, one unscoped Processor with only its currently supported workspace, two Consumers, a laptop/SSD/HDD and two Ready pickups. All directory/access setup completes before requests/reviews. No job is offered or accepted automatically.

**The development demo is now populated**, after the user's explicit authorization of normal directory-review effects on three named existing requests. Only their versions/review state changed; reservations, custody and all other existing rows/files were preserved. See [the actual population results](docs/phase-5-report.md#authorized-demo-population--14-october-2026). Normal repeats now reuse the completed fixture without changing any rows or resetting passwords.

Pending directory additions still refuse collateral changes unless individually authorized using repeated `--allow-review-change=REQUEST_UUID:CURRENT_VERSION` options. The list must exactly match every affected active request and its previewed version; extra/missing/new requests or stale versions stop setup. Any active assignment blocks setup regardless of review authorization. The same checks run again under the existing transaction lock. Normal effects are audited and checked for unchanged reservations/custody; no cancellation, deletion, release, relinking or history rewriting is offered. Do not reset databases or remove work to make a seed pass.

Only when preservation preflight permits it, save genuine source-reference evidence privately outside Git/public paths, then run from a private interactive terminal:

```bash
php artisan app:setup-nairobi-demo ADMINISTRATOR_UUID --apply --reference-evidence=/private/path/public-reference.pdf
```

Enter and confirm a separate **12–128 character password** at each hidden prompt; keep them in your private password manager. The command accepts credentials only through a private terminal: no shared password, password argument, echo fallback or non-interactive input bypass exists. Passwords are normally hashed and never printed/stored in reports or command/event payloads in plaintext. New credentials exist only after the entire transaction succeeds. The account emails are `nairobi-demo-collector-1@example.test` through `collector-3`, `nairobi-demo-processor-1@example.test`, and `nairobi-demo-consumer-1@example.test` / `consumer-2`. No mail is sent. For a forgotten existing demo password, use the existing private local recovery instructions above; rerunning setup never resets it.

For this authorized population, six distinct passwords were securely generated and entered through those same hidden terminal prompts by a private local driver. An owner-only `credentials.json` file is retained outside Git in a mode-0700 temporary directory, with mode 0600 on the file; its exact local path is in the handoff. Open it locally, transfer the entries into your private password manager and remove it when no longer needed. Do not paste its contents into chat or reports. `/tmp` may be cleaned/rebooted; private recovery remains available if a password is lost. The source PDF and private verification scripts are also outside Git. No public credential-file/API option was added to the application command.

Completed command references and existing duplicate normalization provide stable identities. Repeats skip existing records, passwords, user edits, request dates/reviews and commands rather than replaying them; another existing Administrator can reuse the same fixture. If a completed reference is missing, ambiguous, ownership/role changed, or directory eligibility is lost, stop and investigate instead of creating a replacement. A later expired/stale/cancelled demo request is left unchanged; use existing authorized Consumer/Administrator controls for a new decision. New evidence files are removed on transaction rollback only when no retained attachment references them. No pending notices are dispatched.

The two development pickups are **Ready and unassigned**: Amina's laptop + SSD is `EWR-5DE4B4F31DAE103EB7F2257DBBC3E252`; David's HDD is `EWR-0FE8CA44867ACF82159B650307D780E5`. Use **Amina Njeri (Nairobi demo)** (`consumer-1`), **Kamau Mwangi (Nairobi demo)** (`collector-2`) at the simulated WEEE Centre operator, and the **WEEE Centre, Mihango office reference — simulated receiving point** destination for the first journey. Existing Administrator: **Pickup allocation → Review allocation → Offer pickup job**, select **Collector and operator** / **Receiving facility or collection point**, enter **Private allocation reason**, then **Record assignment action**. Kamau: **Offers and accepted work → Open assignment → Accept — not scheduled → Record response**. Before an offer, his queue is correctly empty. Amina: **My requests** shows the assignment milestone after acceptance. David's HDD pickup remains for another offer; Akinyi Otieno (`collector-1`) uses the reused synthetic study collecting operator, and Faith Wanjiku (`collector-3`) uses the simulated WEEE Centre operator. Brian Mutiso (`processor-1`) remains unscoped until an Administrator explicitly grants Point staff access; no processing permission is inferred. These people are fictional and do not represent real staff.

## Physical receipts and visit outcomes (Phase 7)

Apply the forward migrations with `php artisan migrate` and `php artisan migrate --env=testing`; never reset either database. Upgrades check existing physical history/custody and protections before writes, preserve existing rows and backfill no receipt, point party or staff scope. Keep the existing application key and private evidence in restricted backups. A migration refusal requires private investigation, not deletion or invented replacement identities.

For pickup, finish directory/Collector/vehicle setup, fresh **Request review**, offer and named Collector acceptance, then approve the route as described above. The Collector opens **Approved routes → Open ordered stops → Record pickup visit**. Use **Select label** with the printed EWC code or explicitly choose **Scan with camera**. Browser scanning is optional; unsupported/denied camera access leaves manual entry available. Old QR URLs guide staff into authorized work and never provide unrestricted item search. A scanned code only selects an item.

For drop-off, Administrator **Place staff access** (`/admin/point-staff`) links an **existing Processor** to one active reviewed collection point under a reviewed processor/both organization. Scope is explicit; Consumers cannot be converted. Actual scope changes revoke the affected sessions; the Processor signs in again. Finish directory changes before recording a fresh Ready review. Processor **Point intake → Open drop-off visit** exposes only outstanding requests for that point. No pickup assignment or route is introduced for drop-off.

In either visit, choose each actual outcome: **Received**, **Absent**, **Refused**, **Mismatch** or **Safety hold**. Leave unattempted items **Untouched — not attempted**. Compare physical category/subtype/model with the label and attest separately for each Received item; mismatch/hold cannot be attested as received. Record private observations/reasons and actual visit time in UTC (Nairobi minus three hours; minute precision), then either a private JPEG/PNG/non-active PDF up to 5 MiB or an accountable operator record issuer/number with an explicit staff assertion. References are encrypted, never fetched, and are **not independently verified evidence**. No Consumer login/signature is invented. Re-enter private fields/files after validation errors; they are not flashed into session input.

**Record visit outcomes** atomically commits explicit attempts, first receipts, evidence, immutable events, versions, custody and notices. Only Received items release their reservations and change custody: owner party → named Collector user party, or → selected collection-point party with the receiving staff actor recorded separately. Ownership remains unchanged. A drive/storage facet remains one physical identity and one first receipt. Received item editing/cancellation is blocked; a fresh command cannot duplicate a first receipt. An authorized identical retry returns the original result without repeating uploads/custody/notices.

A fully fulfilled pickup assignment closes as **Pickup visit completed**; any outstanding remainder closes as **Visit recorded — remainder needs fresh review**. Remainders retain reservations and require fresh review/offer/acceptance/route. Drop-off remainders require fresh review only. Explicit mismatch/safety discrepancies remain blocking pending a reviewed correction; this increment offers no override/resolution module. Failed attempts never transfer custody.

Consumed stops release their assignment claims while vehicle/Collector claims remain. Continue other still-valid stops through **Approved routes**. When done, use **Finish tour work**, explicitly acknowledge the displayed unfinished list and provide a reason. Unfinished stops remain uncollected/unattempted with accepted assignments/reservations retained for Administrator replanning; only route claims release. Finish records no depot arrival, unloading, delivery or facility receipt. Frozen comparisons stay immutable; only exact receipt-linked projection changes are allowed to preserve execution. Unrelated request/date/observation/directory/access/vehicle changes still invalidate approval.

Consumers see safe visit counts/outcomes under **My requests** and **Receipt notices**. Private evidence/observations are limited to the recording staff within current organization/point scope; safe summaries do not grant contact, serial or photo access. An Administrator sees safe receipt oversight, not receiver impersonation or a custody override. Bounded mismatch/late-conflict records retain no attempted foreign code.

Deliver only supported visit notices with `php artisan app:deliver-receipt-notices --limit=100`. `php artisan schedule:work` also runs this bounded command every minute alongside assignment notices. The receipt command deduplicates by outbox ID and leaves historical/unrelated pending rows untouched. No external mail is sent; real inbox delivery remains unconfigured/unverified. First receipt does **not** authorize later handover, facility processing, sanitization, returns or repeat-first-receipt lifecycles. See [the Phase 7 report](docs/phase-7-report.md) and [deferred acceptance](docs/deferred-acceptance-checks.md).


## Custody handovers and facility acceptance (Phase 8)

Apply the forward migrations on each dedicated database after reviewing preservation/preflight instructions; never reset them. Existing point staff scopes are preserved. **Place staff access** now permits one explicitly reviewed collection-point or facility scope for an existing Processor. Local private provisioning, if needed, remains `php artisan app:provision-staff processor`; no default passwords, automatic role conversion or inferred organization-wide scope exist. Complete **Operators and places** review and exact category/subtype capabilities before selecting the named receiver.

Collector **Custody handovers** lists available held items and outgoing/incoming records. Choose **Prepare handover to [Processor · destination]**, select the manifest, acknowledge as sender, supply time and private evidence/reference and press **Initiate pending handover**. The named receiving Processor opens the handover and uses **Record recipient outcomes** with physical comparison/actual-receipt attestation per item. **Select label / Scan with camera** assists identification only; manual entry remains available. Collection-point Processors can send held items to facilities through the same controls. Completed pickup assignments do not block later handover.

Initiation never changes custody; only recipient-accepted rows do. Missing/damaged/mismatched/refused rows retain sender accountability and an active dispute, without asserting actual whereabouts. Omissions stay pending. Sender/Administrator **Withdraw pending items** releases only pending transfer claims. Administrator **Review discrepancies** can retain investigation or close a claim with evidence using **Close dispute — no transfer/return recorded**. Closure does not acknowledge, rewrite history or declare physical return. A replacement requires new initiation and acknowledgment. No automatic expiry, Collector relay, facility redistribution or processing/sanitization workflow is included. Phase 7 safety/mismatch correction remains separately unavailable.

Evidence uses existing private JPEG/PNG/non-active PDF handling (one file, up to 5 MiB) or accountable issuer/record-number assertions; references are never fetched or described as independently verified. Keep timestamp entry explicitly UTC (Nairobi minus three hours); history shows Africa/Nairobi and server recording time separately. UTC-entry usability remains a deferred review. Private fields/uploads are not retained after rejected submissions.

Handover in-app delivery is limited to its supported committed events:

```sh
php artisan app:deliver-handover-notices --limit=100
```

The existing scheduler runs this bounded command every minute. It does not dispatch unrelated historical outbox entries or external mail. **Handover notices** links reauthorize current scope; old notices do not preserve private access. Consumers see only owned milestones from device detail. See [the Phase 8 report](docs/phase-8-report.md#exact-label-walkthroughs) for complete setup/partial-resolution walkthroughs and actual checks. Real collection-point acceptance, camera/mobile/accessibility acceptance and inbox delivery remain unverified.

## Inspection and pathway approval (Phase 9)

Apply both `2026_10_16` forward migrations on the dedicated databases after isolated upgrade/preservation checks; never reset them. They add frozen inspection/rule/decision ledgers, not demonstration receipts or invented technician observations. Preserve `database/data/inspection-rules-1.0.json`; later rule changes require a new explicit version and forward migration. The independently preregistered [schemas and evaluation cases](docs/evaluation/phase-9-specification.md) distinguish approved whole-device ASSESS-1.0 from PARTS-ASSESS-1.0.

Finish **Operators and places** approval/exact subtype capabilities and **Place staff access** first. After actual collection/drop-off and recipient-accepted facility handover, an active facility-scoped Processor opens **Assessments / Inspection → Open inspection and decisions → Record inspection**. Any currently authorized Processor at that facility can inspect/decide; being the original named receiver is unnecessary. Collection-point scope and Administrator/Collector roles do not authorize facility inspection. An empty queue means no currently held facility items; do not fabricate a receipt to populate it.

Enter technician observations independently of Consumer self-report, explicit UNKNOWN for unavailable/unsafe checks, actual UTC time, private reason and one private attachment/accountable reference. Only keyboard secondary power permits N/A with a reason. **Freeze inspection and recommendation** retains answers, actor/times, evidence provenance, schema and rule checksum. Inspect the first matching rule, priority, missing/conflicting fields and unchanged data gate. HDD/SSD diagnostics never establish sanitization: data-bearing stays YES and their storage facet retains the same physical ID. Fan boundary/electronics uncertainty produces HOLD. No unsafe testing, dismantling or browsing/copying user files is instructed.

Use **Record pathway decision → Record human pathway decision** separately, selecting REFURBISH/RECYCLE/HOLD and giving a reason (including every override). This records a candidate pathway, not execution, data clearance or release. Recorded hard safety/identity/scope restrictions allow only HOLD and persist across later inspections; generic safety/mismatch correction remains unavailable. **Record reassessment** appends a new frozen inspection and leaves its decision pending; earlier decisions remain explicitly Historical. An old identical retry returns its original result without restoring an obsolete current decision.

Private reasons, evidence references and files are not flashed after errors; permitted answer selections remain. Re-enter private information and review current versions after conflicts. Timestamp entry remains explicitly UTC, with Africa/Nairobi history; its Nairobi usability review is deferred. Consumers use **My devices → device detail → View your assessment milestones** for owned safe history, without technician evidence/reasons or other owners' records.

`php artisan app:deliver-assessment-notices --limit=100` delivers only supported new inspection/decision in-app events. The existing scheduler runs the same bounded command; neither drains unrelated historical outbox rows nor sends external mail. **Assessment notices** and evidence links reauthorize access. See [the Phase 9 report](docs/phase-9-report.md) for actual verification and five exact-label walkthroughs. Bench, browser/mobile/accessibility and final integrated acceptance remain deferred; no professional diagnostic or real operational acceptance is claimed.

## Standalone media assurance (Phase 10A; bounded UC11)

Run the normal forward migrations with `php artisan migrate`; never reset either database. The new policy/ledgers introduce no treatment records or media identities. An existing standalone HDD/SSD must have its canonical storage facet, accepted current facility custody, approved exact facility capability and active facility-scoped Processor. **Assessments / Inspection** must have a current non-HOLD human decision (an accepted project prerequisite). Existing safety/dispute restrictions remain blocking; their generic correction workflow is unavailable.

Use **Media sanitization → Open media assurance and history → Record external attempt**, then separately **Record verification → Record execution verification** and **Record validation → Record adequacy validation**. Only supported successful work with satisfactory verification and accepted adequacy can show **Data treatment satisfied (attested)**. The application records external work; it never executes wiping commands, accesses storage devices or reads user files. HDD/SSD stay data-bearing YES. Policy **MEDIA-ASSURANCE-1.0** supports evidenced HDD firmware sanitize overwrite, SSD firmware sanitize block erase and media-specific shredding; other/unknown techniques are record-only. Model support, coverage and residual risk require accountable evidence, not assumptions or a certificate alone. See [the frozen assurance contract and verified sources](docs/evaluation/phase-10a-assurance-specification.md).

Failed/incomplete/unverifiable work may honestly omit unavailable metadata/evidence. Reported success requires executor, tool/equipment (software version where applicable), scope, procedure and private evidence/reference. Every review needs its own evidence and reason. Rejection/negative verification requires a new real attempt; histories are never edited. Newer evidence governs, including newer failure. Any reported destruction retains permanent non-reusability concerns; accepted destruction records neither disposal nor release. Reassessment/dependency drift blocks current applicability: restore legitimate prerequisites, then explicitly **Revalidate applicability → Record applicability revalidation** for still-applicable latest accepted evidence, without inventing repeated physical work.

All event-entry fields are explicitly **UTC** (three hours behind Nairobi); history distinguishes observed/recorded times and displays Africa/Nairobi. Private text/files must be re-entered after validation errors. Consumers use **My devices → device detail → View your media treatment milestones**, with no staff evidence/private references. There are no new notice types or deliveries; existing historical outbox rows are untouched. Real inbox delivery remains unconfigured/unverified.

Phase 10A alone did not support whole-device inventory. Phase 10B below adds observed inventory and bounded coverage; treatment is still recorded per canonical medium, never as a direct parent attempt. Fans, embedded/detached media creation, destructive execution, repair/release, dismantling, batches and disposal are outside this increment. [The Phase 10A report](docs/phase-10a-report.md) contains verification and exact-label recovery walkthroughs. Browser/bench/mobile/accessibility and final integrated acceptance remain deferred.


## Whole-device media inventory and coverage (Phase 10B)

Apply forward migrations with `php artisan migrate`; never reset either database. Existing rows receive no inferred inventory, provenance, completeness or N/A. `processor_media` is a processor-only identity vocabulary, hidden from Consumer registration and ordinary directory intake; no new consumer component category is offered. The three media types are HDD, SSD and other nonvolatile storage. Other nonvolatile storage is inventory-only: **Treatment support unavailable**.

From an accepted whole device in **Assessments / Inspection**, open **Media inventory**. Record a current non-HOLD parent decision before treatment authorization. Use **Record inventory observation** for a genuinely newly identified physical medium, **Recognize existing medium** for an authorized known standalone code, or **Record unresolved inventory** for uncertain presence/count/matching. Unknown serials remain blank. Do not power, dismantle, inspect files or guess physical matching. Record distinguishing placement, actual UTC time and private evidence/reference. New media has evidenced facility-identification control, not an invented earlier first receipt, removal or transfer. Existing labels, ownership, first receipts and treatment history are retained. Staff can use **Printable media label → Print label**; authentication and current facility scope remain required.

Identify every medium, including configuration storage, then **Attest completeness**. Inaccessible/uncertain areas prevent complete inventory. Current technician NO additionally needs **Explicit N/A justification** and complete evidence establishing no applicable storage; an empty list or Consumer NO is insufficient. For each derived HDD/SSD, choose **Authorize media treatment → Authorize [EWC code]**, review the selected medium and record affirmative media-specific checks/evidence. The facility must explicitly support that HDD/SSD subtype through its existing standalone-drive capability; accepting the parent is insufficient. Existing standalone drives retain their own inspection and decision requirements and cannot use the parent authorization shortcut.

Use each drive's **Open media assurance and history** for the existing external attempt → separate verification → separate validation workflow. **Review coverage** combines the current inventory/inspection and every identified medium's live assurance. Unsupported, unresolved, disputed, incomplete, failed or stale facts block coverage. Inventory or assessment changes require fresh completeness/authorization where applicable and explicit revalidation of still-applicable evidence; never invent a new physical attempt. Data-treatment coverage does not authorize reuse, release or disposal. Destruction concerns remain visible permanently and block reusable presentation.

**Correct inventory entry** appends evidence: an unbound observation may remain unresolved or be corrected to not present; a bound identity can only become disputed, retaining its canonical identity, exclusive claim and obligations. Resolving a mistaken canonical association still needs a separately reviewed correction workflow. Omission cannot unlink media or disguise removal/replacement. Independent media movement and general parent custody changes while media is claimed are blocked. Phase 11 below adds only an explicit atomic owner-return exception; detachment/replacement and general co-transfer remain unavailable.

Consumers see safe owned coverage/milestones and canonical media labels, without staff evidence or private references. Historical evidence links reauthorize current facility access. No new notices or outbox dispatch are introduced. Actual inventory, browser/mobile/accessibility and Nairobi time-entry acceptance remain deferred; real operational suitability and inbox delivery remain unverified/unconfigured. See [the Phase 10B report](docs/phase-10b-report.md), [CR-MEDIA-01 contract](docs/design/phase-10b-media-contract.md) and [deferred acceptance](docs/deferred-acceptance-checks.md). This is not universal whole-device sanitization support or complete UC11 acceptance.


## Refurbishment and acknowledged owner return (Phase 11)

A currently scoped Processor opens **Refurbishment / Owner return** after compatible accepted facility custody and a current **REFURBISH** decision. **Record work → Record functional test → Authorize owner return** records genuine external activity; no repair/diagnostic/wiping command runs. Tests use frozen category/part profiles. Failed work/testing requires fresh inspection and decision. Replacement/configuration uncertainty and hard restrictions cannot be cleared by notes or routine reassessment.

Complete live data requirements first: whole-device **Media inventory** coverage, existing HDD/SSD **Media sanitization**, or qualifying basic-fan **Record fan N/A** with current technician NO and explicit private evidence. Unknown, unsupported, disputed, incomplete, stale or destroyed media blocks release. No certificate automatically clears a gate.

Authorization leaves custody unchanged. The existing owner uses **My devices → device/part detail → View your work and owner return → Acknowledge complete receipt**, selects every actually received manifest item and explicitly confirms complete receipt. Only then does one transaction return the parent and all confirmed embedded media together, preserving identities, placement and ownership. No new collection or first receipt is created. No broader recipient, ownership transfer, partial acknowledgment or automatic expiry is supported.

Changed inspection/work/test/inventory/assurance/access facts invalidate authorization without revival; use **Withdraw authorization**, resolve legitimate blockers and authorize afresh. **Report incomplete or mismatched return** closes the authorization as an exception without any custody transfer and blocks routine continuation; reviewed correction remains unavailable. Private evidence reauthorizes current facility control; Consumer milestones omit staff details, notes and references. Historical treatment does not guarantee subsequent owner use. No new notices or external mail are sent.

Both dedicated databases need the forward migrations via `php artisan migrate` (and `php artisan migrate --env=testing` for the dedicated test schema). Never reset either database. See [the Phase 11 report](docs/phase-11-report.md), [approved work/return contract](docs/design/phase-11-reuse-contract.md) and [deferred checks](docs/deferred-acceptance-checks.md). Manual/bench/browser acceptance remains deferred; this is bounded UC12 and does not establish deployment or operational readiness.

### Dismantling and on-site batch accounting (Phase 12)

Apply the four forward migrations with `php artisan migrate` before first use; never reset a database. A pre-use guard finding unexpected existing batch state requires private review, not deletion or history rewriting.

Active approved facility-scoped Processors use **Dismantling / Batches → Prepare batch draft → Save draft selection**. Drafts reserve nothing. Complete current **Assessments / Inspection** RECYCLE decisions and applicable **Media inventory** before **Record actual dismantling**. This records an evidenced assertion of work already performed by people; the application performs no dismantling or hardware operation.

Only intact whole devices with complete confirmed separable HDD/SSD inventory or evidenced empty inventory, and verified basic ICT fans qualify. Every surviving drive must be explicitly confirmed; its original identity, owner, origin and facility custody remain. Standalone-drive dismantling, unsupported/inaccessible media and retained restrictions are blocked. No development prerequisites are manufactured.

Use **Record output observation** for genuinely new material/component/residue lots; surviving drives already have canonical output facets. Unknown output mass stays pending. **Record measurement correction** records a first pending measurement or supersedes its current measured value with reason/evidence; it never creates production. Use kg with at most three decimal places and actual scale/source provenance. Gross/tare requires both values and an exact positive difference. **Attest complete output accounting** is separate from arithmetic balance and is invalidated by later observations/corrections. Prototype tolerance is `max(0.100 kg, 2% of measured input)`. Honest imbalance can be recorded; it blocks reconciliation, never creates automatic loss or recovery yields.

For detached drives, **Authorize detached-drive treatment → Media sanitization / applicability review** provides context 3 using the existing assurance ledger and unchanged method policy. Detachment invalidates old parent applicability; explicitly revalidate justified historical evidence or record genuine external work, without inventing repeated treatment. **Report unexpected media** records a blocking discrepancy without guessing provenance/owner or creating a drive. Resolution is not implemented.

Batch/output **QR / manual label** controls reuse permanent labels; **Find batch or output** requires current facility scope. Consumers use **My devices → device detail → View your batch association**, showing only their own source association and safe aggregate status. Private measurements/evidence and other owners are withheld. All stock remains on site; dispatch eligibility/reservations/deductions, downstream receipt and final recycling closure are unavailable.

See [Phase 12 report](docs/phase-12-report.md), [implementation contract](docs/design/phase-12-batch-contract.md) and [deferred acceptance](docs/deferred-acceptance-checks.md). Preservation uses a retained restricted manifest under `storage/phase-evidence/phase12/`, outside served disks and Git. Its fixed scope is both environment files, all tracked PHP configuration files and every non-symlink regular file recursively under `storage/app`, including historical backups. Do not publish it or regenerate keys. Keep this scope in later comparisons; a newly created inventory is not historical proof. Real weighing/browser/label/mobile/accessibility and integrated acceptance remain deferred; no inbox or operational acceptance is implied.

### Downstream movements and recorded batch closure (Phase 13)

Administrator setup uses **Output acceptance profiles** and **Place staff access** for approved receiving facilities. A profile explicitly matches type, normalized material specification and a supported non-hazardous declaration; device-category capability alone does not authorize a material stream. Storage profiles use `canonical_storage` plus exact HDD/SSD capability. Review versions are retained; changed setup can require withdrawal and a fresh shipment.

Source Processors use **Downstream movements → Open downstream accounting → Prepare shipment draft → Reserve stock → Record actual dispatch**. One original output and one named destination per shipment support multiple partial dispatches. The named Processor uses **Named-recipient inbox**, matches the actual EWC code and records accepted/refused/missing quantities; **Record completion evidence** is separate. Canonical drives move whole and require satisfied supported assurance before dispatch and explicit received-context applicability review afterward. No physical operation is executed by the application.

Stock deducts only at dispatch or actual source-stock loss. Receipt, completion and downstream loss never deduct dispatched stock again. **Close accounted batch** requires zero remaining stock/reservations, evidenced outcomes, cleared media and no blocking discrepancy. Mixed-owner lineage conveys no ownership transfer or individual recovery yield. Unsupported returns, external-only recipients and correction/resolution paths remain blocked. See [Phase 13 report](docs/phase-13-report.md) for exact walkthroughs, recorded checks and deferred manual acceptance.
