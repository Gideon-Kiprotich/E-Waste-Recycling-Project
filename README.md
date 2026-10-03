# Smart E-Waste Collection, Tracking and Recycling Management Platform

University final-year project for Kenya, with Nairobi County as the case study. Phase 0 establishes a native Laravel development foundation; business features have not started. See [the verification report](docs/phase-0-report.md) for verified status and actual results.

## Prerequisites

- Ubuntu with Laravel 13 and a supported PHP version. Laravel 13 supports PHP 8.3–8.5; this lockfile resolves on PHP 8.5 and requires at least PHP 8.4.1 through its Symfony dependencies. Use PHP 8.5 for this checkout. This environment has Ubuntu 26.04.1 LTS and PHP 8.5.4 CLI.
- Composer 2 (verified 2.9.5).
- Laravel's required PHP extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML; additionally **pdo_pgsql** for this application. The Laravel extensions and `pdo_pgsql` are verified loaded in PHP 8.5.4 CLI.
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

After administrator preparation, confirm ownership and contents, then apply only the framework's initial migrations:

```bash
php artisan migrate
php artisan migrate --env=testing
```

No domain migrations or business seeds are included. Do not run `migrate:fresh`, `db:wipe`, or database resets.

## Develop, build, and test

Run the Laravel development server and Vite together:

```bash
composer run dev
```

Open <http://127.0.0.1:8000>; Ctrl+C stops both processes. Alternatively, use `php artisan serve --host=127.0.0.1` and `npm run dev` in separate terminals. For compiled assets, build once and run just the Laravel server:

```bash
npm run build
php artisan serve --host=127.0.0.1
```

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

The integration test checks the actual server/database/role, PostgreSQL major version 16, absence of administrative role privileges, database separation, and temporary transactional read/write access. It rolls back temporary data and never resets a database. A separate HTTP feature test checks the welcome route. A passing welcome test alone does not mean PostgreSQL setup is complete.

After starting the development server, an HTTP smoke check is:

```bash
curl --fail http://127.0.0.1:8000/
```

Stop the server when finished. Dependencies and builds remain local; commit `composer.lock` and `package-lock.json` along with source after all checks pass.

## Required before feature implementation

Supply the actual authoritative Deliverables 2 and 3 in [`docs/requirements/`](docs/requirements/README.md), retaining their original titles/revisions. Supply the reviewed UI prototype and handoff (or an authoritative link with revision/review status) in [`docs/design/`](docs/design/README.md). None has been supplied; no domain schema, business rules, or screens may be invented from them.

Follow [`AGENTS.md`](AGENTS.md) for the agreed stack, scope and small-phase workflow. Do not begin Phase 1 until Phase 0 is verified and the required documents/prototype are reviewed.
