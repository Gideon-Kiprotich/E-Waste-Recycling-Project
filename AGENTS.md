# Project agreement

These standards apply to Phase 0 and every subsequent phase.

## Stack and scope

- Build the Smart E-Waste Collection, Tracking and Recycling Management Platform for Kenya; case study: Nairobi County.
- Use Laravel 13, supported PHP >= 8.3, PostgreSQL 16 with current supported minor updates, Blade, project CSS, and minimal JavaScript. Composer manages PHP; Node/npm and Vite build assets. Keep the setup native to Ubuntu; no Docker, React/Next.js, MySQL, or SQLite substitution.
- Keep one modular application. Later deterministic assessment/routing logic belongs in PHP.
- Later roles: Consumer, Collector, Processor, Administrator. Later scope: device IDs/QR, collection requests, acknowledged custody, human-approved explainable assessment, sanitization records, processing batches, constrained routing, reports.
- Excluded: rewards, blockchain, native apps, AI chat/image classification, resale marketplace, illegal-dumping workflows, official regulator integrations, observer login.
- Before feature work, obtain and review authoritative Deliverables 2 and 3 in `docs/requirements/` and the reviewed UI prototype/handoff in `docs/design/`. Do not invent their contents, screens, business rules, or domain schema.

## Engineering standards

- Follow Laravel conventions, PSR-12, and the project's automated formatter (Laravel Pint).
- Write clear, cohesive code with meaningful names and small, focused methods.
- Separate validation, authorization, business logic, and presentation at the appropriate boundaries.
- Prefer simple solutions; avoid premature abstractions, unnecessary dependencies, and duplicated business rules.
- Use database constraints, transactions, and indexes where correctness or demonstrated query needs require them.
- Protect credentials and personal data; validate input and enforce authorization at the appropriate boundaries.
- Handle errors explicitly and provide useful, safe messages that do not disclose sensitive data.
- Maintain accessible, responsive interfaces.
- Test meaningful behavior, failure paths, and applicable permission boundaries. Keep tests deterministic and isolated.
- Comments should explain non-obvious reasons or constraints; avoid narrating obvious code.

## Phase workflow and verification

- Keep changes limited to the current phase. Complete and verify that phase before beginning another. Phase 0 covers only the repository and development foundation; do not add business features to it.
- Inspect once; batch independent checks; use targeted commands. Repeat checks only after changes or failures justify it. Avoid unnecessary agents, research, and unrelated refactoring.
- Protect existing files, services, and databases. Inspect ownership and contents before changing an existing project database. Never reset unrelated databases, run destructive database commands, broadly upgrade packages, or recursively change permissions. Do not use root by default.
- Use dedicated `ewaste_development` and `ewaste_testing` PostgreSQL databases and non-superuser `ewaste_app`. Enforce test isolation before database access; never use production/development databases or SQLite for tests. Do not bypass the guard or skip the PostgreSQL integration check to conceal prerequisite failures.
- Run formatting and relevant automated checks before committing. Phase 0 requires Composer/platform validation, the frontend build, initial migrations on the dedicated databases, PostgreSQL-backed tests, and an HTTP smoke check. Stop temporary servers.
- Never claim checks passed unless they actually ran successfully. Record passed, failed, and blocked outcomes; report unresolved blockers honestly. Do not mark a phase complete while its required setup or checks remain unresolved.

## Documentation

- Maintain one report per phase: `docs/phase-0-report.md`, `docs/phase-1-report.md`, and so on.
- Each report must explain scope, changes, important decisions, migrations/configuration, actual verification results, limitations, and handoff instructions. Put detailed explanations in the phase report rather than the commit message.
- Update `README.md` when setup or usage changes.

## Git

- Use short, plain commit messages, such as `Set up project`, `Add authentication`, or `Add device registration`. Phase 0's pending commit message is `Set up project`. Do not rewrite existing history solely to change a commit message.
- Commit only after required checks pass. Inspect the staged diff and exclude secrets, dependencies, generated builds, runtime artifacts, temporary/evidence files, and unrelated changes.
- Keep `.env`, `.env.testing`, credentials, and keys out of Git and tool output/reports. Track safe environment examples and both dependency lockfiles.
- Do not push or create remotes without an explicit request. Use the configured Git identity; ask if missing, never invent it or change global configuration.
