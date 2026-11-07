# Working in this repository

- Work in the root source trees and `hinaplanon_colab_bridge/`. `backup_pre_*` are historical copies; `deploy/infinityfree/upload/` is a staged deployment copy, not the source. Exclude these duplicates and virtual environments from searches unless working on them explicitly.
- Native PHP MVC, with no Composer/npm bootstrap. `public/index.php` registers the `App\` → `src/` autoloader and all routes. URLs use `index.php?route=route-name`, not framework-style paths; authorization lives in controllers, not route middleware.
- README feature/access descriptions are not all current. Consult controllers, `views/layouts/main.php`, and `tests/test_feature_removal_roles.php` before restoring missing role-specific features; removals affect residents, responders, and officials. `SosController` intentionally denies both SOS routes.

## Commands and verification

Run from the repository root:

- Local server: `php -S localhost:8000 -t public`; login: `http://localhost:8000/index.php?route=login`. `serve.bat` instead binds to `0.0.0.0` (all interfaces).
- Database-free checks: `php -l path/to/file.php`; `php tests/test_activity_calendar.php` for calendar date/filter logic; `python tests/test_ml_client.py` for PHP HTTP-client fixtures (requires PHP cURL, no running API).
- Preferred workflow QA: `python tests/ui_sandbox.py --label current`. It snapshots SQLite, copies the app into `%TEMP%/opencode/padayon-ui-*`, applies the resource-request migration there, and resets the copy before each PHP suite. It reports failures and exits nonzero; inspect its `qa-results.json` and per-suite logs. It does not run browser or live-API tests.
- Main QA script: `php tests/run_all_tests.php`. Despite its name, it does **not** invoke the separate `test_*.php` scripts.
- Focused suites: `php tests/<name>.php`, where `<name>` is `test_feature_removal_roles`, `test_field_report_details`, `test_relief_hub`, `test_relief_claims_oversight`, `test_preparedness_management`, or `test_disaster_resource_requests`. Run these in a disposable copy, not against working data.
- Render smoke check: `php tests/probe_routes.php`. This calls controllers directly, not HTTP endpoints or the router. Inspect output for `[FAIL]` / `FAILED`; the prober and main QA script do not set a failing exit code for assertion/probe failures, and the prober prints a success footer even after failures.
- `Auth::redirect()` exits on web requests but deliberately returns under CLI. Direct-controller tests must inspect denial flashes/output, not assume redirects stop execution or that an empty render proves access succeeded.
- Direct PHP workflow/QA scripts use the configured application database and seeded demo accounts (README account table), not isolated fixtures. They change approvals, inventory, reports, evaluations, and audit data without a suite-wide rollback; do not run suites concurrently against the same database.
- Browser QA requires Python Playwright + Chromium, a sandbox from `ui_sandbox.py`, and PHP serving that sandbox on a separate loopback port: `php -S 127.0.0.1:8011 -t <sandbox>/public`. Run `python tests/test_ui_browser.py --sandbox <sandbox> --url http://127.0.0.1:8011` or `tests/test_activity_calendar_browser.py` with the same arguments. Both verify the served copy and reject port 8000; they write fixtures only in the sandbox.

## Database and deployment traps

- `config/database.php` defaults to PDO SQLite at `database/disaster_recommender.sqlite`; sessions are stored in `database/sessions/`. Both locations must be writable. The SQLite path is hard-coded, not controlled by `DB_NAME`.
- MySQL uses process environment variables `DB_DRIVER=mysql`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`; no `.env` loader is bootstrapped. `src/Core/Database.php` falls back to the local SQLite file if MySQL connection fails; check `Database::getDriver()` before assuming a command touched MySQL.
- `php database/seed.php` executes the SQLite base schema and inserts demo data; for MySQL it assumes the schema already exists. Do not treat seeding as a complete migration runner: upgrades are standalone PHP files, and migrations such as `database/migrations/20261103_purok_ml_xai.php` use SQLite-specific SQL and also rewrite master data. Inspect before running; there is no verified universal migration order.
- Resource-request extension: `php database/migrations/20261106_disaster_resource_requests.php` creates supplemental tables without rewriting incidents. `ResourceRequestSchema` rejects MySQL (including fallback), and `DisasterResourceIntake` mutations require SQLite; this workflow is not covered by the application's general dual-driver support.
- `deploy/infinityfree/prepare.ps1` copies source into `upload/_app/`, exposes assets at the upload root, and substitutes deployment templates. It deliberately refuses to run if `upload/` already exists; do not delete the existing package merely to bypass this guard.
- `deploy/infinityfree/ADMIN_LOGIN_PRIVATE.txt`, password-reset artifacts, the database, and session files contain sensitive state; do not copy their contents into documentation or responses.

## ML pipeline

- Request recommendations load `ml/data/active_model.json` via `src/ML/RecommenderEngine.php`; purok assessments use a separate `ml/data/active_purok_model.json` via `PurokAssessmentEngine`. Do not conflate these models.
- Native request-model training: `php ml/train.php --source=demo --seed=42 --depth=5`. This may import synthetic records into the database and overwrites `ml/data/active_model.json`; it is not a read-only verification command.
- In `ml/train.php`, omitting `--source=demo` trains only on non-synthetic, verified records and exits successfully without training when fewer than 10 exist. Keep synthetic demonstration records distinct from verified official data.
- `php ml/train_models.php` instead compares Decision Tree/Random Forest candidates using all historical records (including synthetic/unverified), writes `active_purok_model.json`, and updates `model_versions`. It is not interchangeable with `ml/train.php`; both trainers deactivate existing model-version entries.
- Python/scikit-learn is optional, not required to run the PHP application; `ml/train_decision_tree.py` also targets `active_model.json`, so do not run alternative trainers casually over the active artifact.

## Python inference bridge

- `pyproject.toml` targets Python 3.12 and sklearn 1.6.1, but bridge launchers use `hinaplanon_colab_bridge/.venv/`, not the root `.venv/`. Install from the bridge's `requirements.lock.txt`; it includes uvicorn, absent from the root dependency list. Setup details: `hinaplanon_colab_bridge/README.md`.
- Start the API with `powershell -NoProfile -ExecutionPolicy Bypass -File .\hinaplanon_colab_bridge\start-api.ps1` (loopback port 8001). `start-php.ps1` in the same directory starts PHP on 8000 with the Colab opt-in flag; ordinary PHP startup does not enable that flag. Restart PHP to inherit environment changes.
- Do not conflate endpoints: `ColabModelClient` calls `/predict` only with `HINAPLANON_COLAB_MODEL_ENABLED=1` (Critical/High/Moderate/Low contract, supplied joblib lacks Low); `DecisionTreeApiClient` calls `/predict-decision-tree` by default unless `HINAPLANON_DECISION_TREE_API_ENABLED=0` (Urgent/High/Medium/Low, reads native `active_model.json`). The latter returns null on failure, not a fabricated fallback priority.
- Both clients use server-side `HINAPLANON_ML_API_BASE_URL` (default `http://127.0.0.1:8001`) and optional `HINAPLANON_ML_API_TOKEN`. Remote URLs require HTTPS. Keep the supplied FastAPI service on loopback: sending a Bearer header does not make this unauthenticated service secure for public hosting.
- With the API running: `python tests/test_ml_client.py --live`; full bridge checks: `.\hinaplanon_colab_bridge\.venv\Scripts\python.exe hinaplanon_colab_bridge/verify_bridge.py` (uses disposable SQLite copies). Only load trusted joblib files; deserialization executes code. Model confidence is not validated operational accuracy.

## Data and workflow boundaries

- Preparedness history requires source reconciliation, not just `is_verified=1`: `PreparednessOverview` checks row fingerprints/reviewer/source in `config/preparedness.php`; residents additionally require `public_release=true`. Do not promote synthetic or migration-inferred records to official evidence. See `PREPAREDNESS_MANAGEMENT.md` for provenance and missing event links.
- Preparedness prediction/policy previews do not save allocations or reserve stock. Resource intake is separate: Barangay Head submits, ICDRRMO independently reviews, and allocation deducts stock only afterward. Preserve revision checks, operation-key idempotency, and resource/unit compatibility in `DisasterResourceIntake`.
- Calendar dates use `Asia/Manila`, including naive database timestamps; date-only records have no invented time. Elapsed dates do not imply Completed status. `src/Support/ActivityCalendar.php` is the projection logic; schema limitations and browser checks are documented in `ACTIVITY_CALENDAR.md`.
