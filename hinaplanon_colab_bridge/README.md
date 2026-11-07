# Hinaplanon local Colab bridge

## Installed setup

The supplied joblib is unchanged. This Windows setup uses isolated CPython 3.12.16 in
`.python/` and dependencies in `.venv/`; no system Python/PATH change is required.
PHP must be on PATH with cURL and PDO SQLite available. Scikit-learn is pinned to
**1.6.1**, matching the model's serialized metadata. `requirements.lock.txt` records
the tested environment; `requirements.txt` is the direct dependency list.

Only load trusted joblib files: deserialization can execute Python code.

## Start both services

From the project root, in **terminal 1** (PowerShell):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\hinaplanon_colab_bridge\start-api.ps1
```

In **terminal 2**, also from the project root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\hinaplanon_colab_bridge\start-php.ps1
```

The per-process execution-policy option does not change your saved policy.
API health: http://127.0.0.1:8001/health; interactive API: http://127.0.0.1:8001/docs.
PHP login: http://127.0.0.1:8000/index.php?route=login. Stop each with Ctrl+C.
Both bind only to loopback. If a port is occupied, stop your own previous server;
do not terminate unrelated processes. PHP's opt-in flag is scoped to its launcher.

Equivalent manual commands (separate terminals, from project root):

```powershell
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe -m uvicorn ml_api:app --app-dir hinaplanon_colab_bridge --host 127.0.0.1 --port 8001
```

```powershell
$env:HINAPLANON_COLAB_MODEL_ENABLED = '1'
$env:HINAPLANON_ML_API_BASE_URL = 'http://127.0.0.1:8001'
php -S 127.0.0.1:8000 -t public
```

To run the unchanged default PHP behavior, start PHP without that flag (or set it
to `0` before starting PHP). No `.env` file is loaded by this project.

## Integration and compatibility

- `src/ML/ColabModelClient.php` is the implementation; the bridge-folder PHP file
  is a compatibility loader, not a second implementation.
- `PurokAssessmentEngine` tries Python only when the flag is `1` and rule-only
  mode is not forced. Failure/invalid output falls back to the existing PHP Random
  Forest, then the existing rule-based evaluation. One failed HTTP call disables
  retries for the remainder of that PHP request (1-second connect, 3-second total timeout).
- No schema, role, UI template, native model, scoring/blending, allocation,
  inventory, or approval logic was replaced. Existing assessment-method text in
  recommendation views identifies Colab results as exploratory and not validated.
- Assessments remain cached in `purok_assessments`. Starting the services does not
  recalculate or overwrite them; use the existing authorized recalculation workflow
  when you deliberately want new assessments. Normal recalculation retains its
  existing database-write behavior. Setup tests use copies instead.
- Python accepts **six named raw features**: `disaster_type`, `severity_level`,
  `purok_risk_level`, `is_urgent`, `affected_households`, `affected_population`.
  The PHP extractor's four extra damage/vulnerability/road features stay in the
  native model/rules and are not sent to the six-feature Python pipeline.
- Actual trained output classes are **Critical, High, Moderate**: this artifact
  **cannot predict Low**. The unchanged rule calibration can still assign Low.
- Trained severities are `Critical`, `High`, `Medium`; purok exposures `Moderate`
  and `Low` are unseen. The fitted encoder ignores unknown categories. The API
  returns `feature_warnings` and PHP logs them; no speculative category remapping
  or retraining is performed. The model was trained on `Flood` only.
- Startup checks the fitted pipeline, feature names, classes, sklearn version,
  and a real prediction. `/health` reports categories, limitations, and model SHA-256.
  Prediction probabilities use a 0–1 scale; confidence is 0–100, **not accuracy**.

This is an exploratory candidate, not validated operational decision support.
The supplied model SHA-256 is
`ec7b030a6f60290c26eb2ba4560d9dd6ea3bdb34161d6f89c73d611bbd98fab3`.

## Verification and errors

With the API running, from the project root:

```powershell
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe hinaplanon_colab_bridge\verify_bridge.py
```

This compares five real HTTP predictions with direct joblib inference, rejects five
invalid requests, and tests all puroks using disposable SQLite backups in five modes:
Colab, Colab without a native model, native only, rules only, and forced rules.
It checks ranking/allocation invariants and that users/inventory are unchanged.
It also verifies SHA-256 preservation of the working DB and all supplied models.
It does not claim to run the project's separate feature/role regression suites.

For a real outage test, stop the API first, then run:

```powershell
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe hinaplanon_colab_bridge\verify_bridge.py --api-down
```

Restart the API afterwards. Failures exit nonzero with `FAILED:`. HTTP 422 describes
invalid features/predictions. Startup errors (missing/incompatible joblib, version
mismatch, occupied port) appear in the API terminal. PHP reports connection/response
errors to its error log while preserving fallback service; the last bridge failure
is also available via `ColabModelClient::lastError()`.

To reinstall the tested packages into the existing environment:

```powershell
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe -m pip install -r hinaplanon_colab_bridge\requirements.lock.txt
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe -m pip check
```

On a different machine, install Python 3.12 and create `.venv` using
`py -3.12 -m venv hinaplanon_colab_bridge\.venv` first. Do not move/copy the existing
virtual environment between machines; it contains absolute paths.

## Deployment limit

Keep port 8001 on localhost; no public authentication/transport security is provided.
On shared hosting, `127.0.0.1` means the hosting server, not your laptop. Leave the
flag disabled there unless a compatible secured inference deployment is arranged.

The PHP client supports server-side `HINAPLANON_ML_API_BASE_URL` and optional
`HINAPLANON_ML_API_TOKEN` (Bearer header). Remote URLs must use HTTPS. A remote
deployment must actually enforce token authentication at its API/gateway; configuring
the PHP header does not secure the current localhost-only Python service by itself.
The integrated five-tab preview and source-reconciliation limits are documented in
`../PREPAREDNESS_MANAGEMENT.md`. The preview never saves an allocation or prediction.
