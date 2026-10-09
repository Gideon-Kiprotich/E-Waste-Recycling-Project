# Smart E-Waste Collection, Tracking and Recycling Management Platform

University final-year project for Kenya, with Nairobi County as the case study. The native Laravel/PostgreSQL foundation, account access, owned devices/standalone parts, consumer collection requests and Administrator directory/request review are implemented. Assignment, physical collection and processing remain for later phases. See [Phase 0](docs/phase-0-report.md), [Phase 1](docs/phase-1-report.md), [Phase 2](docs/phase-2-report.md), [Phase 2A](docs/phase-2a-report.md), [Phase 3](docs/phase-3-report.md) and [Phase 4](docs/phase-4-report.md) for actual verification and limitations. Phase 3 is closed with user-reported request/live-map acceptance; its specific unverified checks remain documented. Phase 4 is accepted for closure following the user's confirmation that the refined directory forms and workflows work satisfactorily; its specific unverified checks and operational limitations remain documented.

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

The command requires `APP_ENV=local`, validates the email/password, refuses existing emails, and never changes an existing account. Use synthetic account information and choose private passwords; no default credentials are shipped. Use each account at `/login`, then check `/consumer`, `/collector`, `/processor` or `/administrator`. Each account reaches its own minimal landing page; other role pages deny access. These pages do not grant organization/facility permissions or implement later operational tools.

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

Back up the private `APP_KEY` with the database and private photos. It protects identifiers and context-separated comparison/command hashes (`app-key-context-1`). Do not regenerate it after storing records: reviewed key rotation must rewrap encrypted identifiers and maintain/recompute comparison hashes and retained retry hashes. No automated key rotation, redaction, malware scanning or notification worker is claimed. The small transactional outbox retains pending event references for a later notification phase.

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
