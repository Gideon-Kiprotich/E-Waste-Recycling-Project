# Phase 0 — Repository and Development Environment

Date: 3 October 2026 (Africa/Nairobi).

**Status: complete.** The native Laravel/PostgreSQL foundation and every required runtime check passed. No business feature or Phase 1 work was started. The initial local commit uses `Set up project`; nothing is pushed.

## Scope and changes

- Created the official Laravel 13 application directly in `/var/www/html/E-Waste-Recycling-Project`, preserving the existing hidden environment directories and standard framework structure.
- Initialized Git on `main`, retained the existing identity, and prepared Composer/npm dependency lockfiles. Secrets, dependencies, generated builds, runtime files and local evidence are ignored.
- Added a minimal project-identifying Blade welcome page, project CSS, minimal JavaScript, and normal Vite build/development scripts. System fonts avoid a network dependency for rendering.
- Configured native PostgreSQL development/test environments, generated separate application keys, and retained only the three framework migrations. The seeder is empty; no domain migration, business record or operator/device fixture was added.
- Added guarded PostgreSQL testing and meaningful welcome/connectivity tests. Removed the scaffold's trivial unit assertion and automatic SQLite creation/migration commands.
- Added the agreed engineering, documentation, testing and Git standards in `AGENTS.md` for every phase; `CLAUDE.md` points to that agreement. Detailed explanations belong in one report per phase; commit messages remain short and plain.
- Updated README with installed prerequisites, verified cluster/port, native setup, safe password recovery, formatter/build/migration/test commands and document handoff locations.

## Verified environment and decisions

- Ubuntu 26.04.1 LTS (Resolute Raccoon), running as ordinary user `gideon`.
- PHP CLI 8.5.4, Zend OPcache 8.5.4; `pdo_pgsql` is loaded. Installed driver package: `php8.5-pgsql` 8.5.4-0ubuntu1.3.
- Composer 2.9.5; Git 2.55.0. Existing Git identity retained; no global identity/configuration change.
- Official scaffold `laravel/laravel` v13.10.1; installed Laravel framework 13.34.0.
- PostgreSQL server/client 16.15 (Ubuntu 16.15-1.pgdg26.04+2); `postgresql-common` 290ubuntu1.
- `pg_lsclusters`: cluster `16/main`, online, port `5432`, cluster owner `postgres`, data directory `/var/lib/postgresql/16/main`.
- Node.js v26.8.1; npm 11.19.0; Vite 8.3.2; Laravel Vite plugin 3.2.0; concurrently 10.0.5.
- PHPUnit 12.5.37; Laravel Pint 1.32.1.

Laravel's [official prerequisites](https://laravel.com/framework/docs/deployment) and [PHP support policy](https://laravel.com/framework/docs/releases) were consulted during setup. The existing supported PHP 8.5.4 runtime was retained. The lockfile includes Symfony packages requiring PHP >= 8.4.1; use PHP 8.5 for this checkout. PostgreSQL's [Ubuntu instructions](https://www.postgresql.org/download/linux/ubuntu/) and [version policy](https://www.postgresql.org/support/versioning/) informed native setup. No version/stack substitution, Docker container, frontend application framework, virtual host, TLS, or application system service was introduced.

Local sessions/cache use files and queues run synchronously; Phase 0 needs no worker or Redis. The local development server remains the supported application entry point.

## Database verification and preservation

Administrator package/database preparation was performed outside the agent. The installed extension/packages and cluster are now visible in this execution environment; no reinstallation or broad upgrade was attempted.

Read-only inspection bootstrapped Laravel separately with each private environment file and verified the actual live server, role, owners, schemas, permissions and existing tables before migrations:

- Development: `.env` → `pgsql`, `127.0.0.1:5432`, database `ewaste_development`, role `ewaste_app`, empty `DB_URL`.
- Tests: `.env.testing` → `pgsql`, `127.0.0.1:5432`, database `ewaste_testing`, role `ewaste_app`, empty `DB_URL`.
- Both authenticated servers reported version number `160015`, cluster name `16/main`, server port `5432`, and their respective expected database names.
- Both databases are owned by `ewaste_app`. The role has login enabled and no superuser, database-creation, role-creation, replication or RLS-bypass privilege. It has no role memberships.
- The role has CONNECT/CREATE/TEMP permissions within its two owned databases. Their only non-system schema is `public`, owned by `pg_database_owner`, with the application permissions needed for migrations.
- Both databases had no application tables before migrations. No existing data was reset, dropped or overwritten, and no unrelated role, database or service was changed.

Initial connections failed password authentication. Direct PDO diagnostics confirmed that Laravel read the saved values correctly and no inherited environment variable overrode them. Authentication succeeded after the user saved the matching private credentials in both local environment files. No password was printed, requested in chat, put in source, or changed by an agent database command. No application key was regenerated during this resume.

Only the three framework migrations were applied to each database:

```text
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
```

After migrations/tests, each database contains nine framework tables. `migrations` has three records; `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs` have zero records. The temporary connection-probe table does not remain. No business seeds or sample data were inserted.

## Test isolation and actual verification

All final required checks passed:

- `composer validate --strict` — manifest and lockfile valid.
- `composer check-platform-reqs` — every reported platform requirement passed, including `ext-pdo_pgsql` 8.5.4. No platform requirement was ignored for this verification.
- `php vendor/bin/pint --test` — full project formatting check passed.
- `npm run build` — Vite 8.3.2 production build passed; generated CSS/JS and manifest remain in ignored `public/build/`.
- `php /tmp/ewaste-live-database-check.php .env ewaste_development` and its `.env.testing ewaste_testing` equivalent — both live connections, server identity, role privileges, database ownership and pre-migration contents passed independent read-only inspection. These temporary verification scripts are not repository deliverables.
- `python3 /tmp/ewaste-safety-check.py` — unsafe development and production database names, and cached development configuration, were each rejected by the shared test guard before a database transaction hook or test body executed. Temporary marker files proved neither boundary was reached. Probe files and temporary configuration cache were removed in cleanup.
- `php artisan migrate --no-interaction` — all three framework migrations passed on `ewaste_development`.
- `php artisan migrate --env=testing --no-interaction` — all three framework migrations passed on `ewaste_testing`.
- `composer test` — **2 tests passed, 13 assertions**, with no failures/errors. It cleared cached configuration first.
- Post-test database inspection — framework row counts checked, no domain/seed data or persistent temporary test table found.
- `python3 /tmp/ewaste-http-smoke.py` — final post-migration server at `http://127.0.0.1:44453/` returned HTTP 200 and the project identity; both built CSS/JS URLs returned HTTP 200. The complete temporary server process group was stopped in cleanup, and the final port-listener check was empty.
- Staged review — source paths and diff checked for secrets, unrelated changes, dependencies, builds, temporary/runtime files and whitespace errors; both lockfiles included and private/generated paths excluded.

PHPUnit pins both `$_SERVER` and environment sources to the dedicated local test connection/database/role and clears inherited database URLs. The shared test base rejects cached configuration, wrong database/connection/role/endpoint, a nonempty database URL, and a test name matching development before database traits execute. The earlier inherited production/development environment probe also verified the pinned sources.

The PostgreSQL integration test checks the actual database/role, development/test separation, PostgreSQL major version 16, absence of administrative role privileges, and temporary transactional read/write behavior. Test data rolls back; the suite never resets databases or substitutes SQLite.

Earlier missing-package/platform failures and this resume's initial authentication failures were not counted as passes. They are resolved in the final results above. No runtime checks were repeated without a changed prerequisite or configuration state requiring verification.

## Git and handoff

Primary branch: `main`. Initial commit message: `Set up project`. This report and the reviewed foundation source/lockfiles belong to that commit; retrieve its hash with `git log -1 --format='%h %s'`. No remote or push was created. Local environments, credentials, keys, dependencies, builds and runtime/evidence artifacts remain untracked and ignored.

Start development from the repository root:

```bash
composer run dev
```

Open `http://127.0.0.1:8000/`; Ctrl+C stops Laravel and Vite. Build assets with `npm run build`, and run isolated tests with `composer test`. See [README](../README.md) for installation and full verification commands. Do not run database resets or parallel tests against the shared test database.

There are no unresolved Phase 0 setup/check blockers. Before feature implementation, supply and review actual Deliverables 2 and 3 in `docs/requirements/` and the reviewed UI prototype/handoff in `docs/design/`. Their contents, domain schema and screens have not been invented. Phase 1 has not begun.
