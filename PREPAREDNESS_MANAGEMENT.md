# Integrated Disaster Preparedness Management

## Implemented scope and important limits

The existing `preparedness` and `preparedness-calendar` routes now share five
sections within one Disaster Preparedness Management sidebar entry. The default
calendar still opens the existing activities calendar and keyboard dialogs.
The former separate AI sidebar entry is removed; existing recommendation/detail/
review routes remain available within the integrated allocation section. Existing
field reports, resident claims, activity creation/evaluation and authentication
workflows are retained, not recreated.

This is a **read-only integration and experimental planning preview**, not a
completed source-reconciled event registry or production-validated recommender.
It does not invent missing event links or write operational decisions from a preview.
The remaining data/schema work below is necessary before claiming the full
event-to-allocation-to-distribution lifecycle is implemented.

### Five sections

1. Historical & Current Disaster Events: source rows, reconciliation warnings,
   system-verified incident records and dates. Archive dates do not imply active
   disasters. Search/date filters operate on recorded rows.
2. AI Affected Purok Analysis: per-purok map-only planning baseline and coverage
   indicators; native Decision Tree inference is eligible only when reconciled
   historical inputs exist. Current data has **zero eligible historical rows**,
   so no verified historical impact ranking or ML-derived area priorities are claimed.
   Admins can preview the existing experimental FastAPI classifier on a verified
   system incident. That output is not an official affected-area determination.
3. Historical & Current Resource Allocation: authorized existing allocation records,
   inventory per resource/unit, legacy quantity-state limitations and a link to
   existing human review. Responders see only approved/modified allocations on
   incidents assigned to them. No cross-purok bulk stock allocation is performed.
4. Historical & Current Relief Goods Distribution: reads existing claims without
   auto-enrolment or copying records. Residents get only their own rows and a link
   to the existing Hub/Claim Ayuda workflow. Staff views omit personal identifiers.
5. Historical & Current Disaster Preparedness Activities: the original calendar,
   creation/evaluation actions, recorded turnout and supplies-used descriptions.
   Coverage counts are not proof of effectiveness or event-specific training need.

## Data audit / sources

Read-only audit of the configured SQLite database found 24 puroks, 140 historical
rows, 72 system requests, 46 legacy recommendations, 30 claim rows and 4 activities.
These are application record counts, **not verified disaster statistics**.

- 120 historical rows are synthetic and excluded from all official/history analysis.
- The remaining 20 rows all originate from
  `database/migrations/20261103_purok_ml_xai.php:419–468`. Despite `is_verified=1`,
  this migration inferred severity, urgency, labels, requested/allocated quantities,
  and affected population (beneficiaries × 4.2), with a hard-coded `2014-02-13` date.
  They remain unchanged but are quarantined from source-confirmed conclusions.
- Source documents are present under `datasets/` and `data/hinaplanon_raw/`, including
  the BDRRM Plan 2024–2026, emergency response/action plans, Secretary PDF,
  `Totally and Partially Listing.xls`, and `TS Basyang - Not final.xlsx`.
  Their presence does not authenticate migration-derived quantities. The workbook
  is explicitly not final; event dates and purok-level measurements still need
  original-document reconciliation. No new document imports were performed.
- Map classifications come from the existing purok master data and its
  `hazard_source_reference`; the existing Leaflet map/routes remain unchanged.
  No new coordinates, boundaries or map classifications were generated.
- Queries scope exact `Hinaplanon` / `Barangay Hinaplanon` names, not substring
  matches that might include Upper Hinaplanon.
- Verified requests mean recorded `verified_at` and verifier ID, not independent
  proof of a current official disaster. Existing demo/system records are not
  promoted to official statistical conclusions.

### Reconciliation and public release

Import verification defaults alone are insufficient. `config/preparedness.php`
defaults to an empty reconciliation register. After an authorized reviewer checks
original documents and corrects records through an appropriate audited process,
use `PreparednessOverview::historyFingerprint($row)` to identify the exact reviewed
fields. A register entry has this server-side shape:

```php
'reconciled_history' => [
    // '<actual row fingerprint>' => [
    //     'reviewer' => '<authorized reviewer reference>',
    //     'source_reference' => '<original document/page and review date>',
    //     'public_release' => false,
    // ],
],
```

Do not add entries based merely on the existing verified flag or source label.
The known inferred legacy source remains excluded. Missing/invalid numerical
features and invalid/future archive dates are ineligible. Changing reviewed fields
invalidates their fingerprint. Resident historical access additionally requires
explicit `public_release=true`. This small configuration guard is not a replacement
for a future audited publication/event-record management workflow.

## Permissions and privacy

| Role | Integrated access |
|---|---|
| Resident | Explicitly released/reconciled history, existing activities, personal claims; operational requests, inventory and internal predictions withheld |
| Responder | Scoped source/system information, read-only planning overview, assigned approved allocations, activities; no prediction submission or allocation approval |
| Barangay Head | Internal read projections, experimental prediction/policy preview; existing authorized management/review actions |
| ICDRRMO | Same internal preview plus existing authorized oversight/review workflows |

The new POST route explicitly checks the admin roles and CSRF token before any API
call. It accepts an existing verified incident ID, **not arbitrary browser-supplied
impact features**. Features are reloaded server-side. The projection rejects unknown
roles and avoids personal claim fields. Existing controllers remain authoritative
for their preserved actions. No new role or authentication permission was introduced.

## ML and quantity semantics

- Native `active_model.json`: existing six-feature Decision Tree preserved.
  SHA-256 `5058a3eea38feb68afff300288e113be3f9fa7b3b43afe2399821ca066bcbf99`.
  It is not assumed to have verified training provenance simply because it exists.
- `active_purok_model.json`: existing native Random Forest preserved, separate from
  the request-model artifact. SHA-256
  `eeaeb3b2e043727b4fa3933a0da5eb1b4342f2f7eed7b963fd70ea5bff1c0cb9`.
- Supplied Python joblib: unchanged Random Forest candidate, sklearn 1.6.1,
  SHA-256 `ec7b030a6f60290c26eb2ba4560d9dd6ea3bdb34161d6f89c73d611bbd98fab3`.
  Its trained classes are Critical, High and Moderate, **not Low**; trained disaster
  type is Flood. Unknown categorical inputs receive compatibility warnings.
- Admin preview shows priority, model-reported confidence, class probabilities,
  source incident ID, recorded six features, model hash and feature warnings.
  API training date is unavailable and is explicitly shown as Not Recorded.
  Preview provenance is displayed, not persisted as a new recommendation.
- An optional independent policy preview uses **recorded households × a
  reviewer-proposed units-per-household rate** (1–100). This is a proposed policy,
  not an observed need or ML-predicted quantity. Proposed units are capped at
  remaining stock and unmet policy demand is shown. Resource units stay separate.
  It evaluates only one incident/resource scenario at a time, not simultaneous
  allocations or reservations. Approval must revalidate stock in the existing
  workflow. No prediction is automatically transferred into an approved allocation.
- Unreconciled history, inferred damage/population, unspecified activity resources,
  historical shortages and donor receipts are not supplied as invented model inputs.
- Recommendation GET no longer regenerates/replaces an empty or filtered assessment
  cache. Deliberate existing admin recalculation retains its original behavior.

## Database changes and unresolved lifecycle requirements

**No migrations, live database edits, imports or training were run.** New code reads
existing tables: barangay, purok, historical_disaster_records,
disaster_request_form, recommendation, supply, relief_beneficiaries,
preparedness_activities and event_evaluations (existing model queries also join users).

Still missing or insufficiently represented:

- Stable official event registry and audited event-to-history/activity/claim links.
  Existing request IDs link incident recommendations, but claims/activities have no
  event foreign key. Names/dates were not used to guess links.
- Separate immutable original recommended, approved, reserved and distributed
  quantities. Legacy recommendation review overwrites its quantity field. Existing
  request logs are retained; ambiguous states are explicitly Not Recorded.
- Supplier/donor receipts, organizations, resource-specific verified historical
  shortage records, budgets, activity requirements/allocations and attachments.
- Approved public event/distribution publication controls and responder distribution
  assignments. Records without these controls are withheld, not implicitly published.
- A source-reconciled labeled dataset sufficient for event-level impact rankings,
  validated priorities and cross-purok quantity policies. No new official accuracy
  statistics are reported. Event timeline visualizations/resource charts beyond the
  existing workflows are not implemented in this integration.

These need a separately reviewed non-destructive schema/workflow extension and
source reconciliation, not inferred backfills. No staged deployment files were changed.

## Run and test

From the repository root, terminal 1:

```powershell
.\hinaplanon_colab_bridge\.venv\Scripts\python.exe -m uvicorn hinaplanon_colab_bridge.ml_api:app --host 127.0.0.1 --port 8001
```

Terminal 2 (stop your own existing PHP server first if port 8000 is already in use):

```powershell
$env:HINAPLANON_COLAB_MODEL_ENABLED = '1'
$env:HINAPLANON_ML_API_BASE_URL = 'http://127.0.0.1:8001'
php -S 127.0.0.1:8000 -t public
```

The launchers in `hinaplanon_colab_bridge/` are also supported; the API launcher now
uses port 8001. No `.env` loader exists. Existing PHP server processes must be
restarted to inherit changed environment variables. Credentials/configuration are
never sent to browser JavaScript. `HINAPLANON_ML_API_TOKEN` optionally adds a Bearer
header. Remote URLs require HTTPS, and the remote API/gateway must enforce real
authentication. The supplied local FastAPI service is **not** a secured public service.
Redirects are not followed; connect/total timeouts are 1/3 seconds; responses are
limited to 64 KiB and validated for labels, probabilities, confidence and model hash.

Open `http://127.0.0.1:8000/index.php?route=preparedness-calendar` after login.
As Barangay Head/ICDRRMO, select the AI tab, choose a verified system incident,
optionally choose a resource/rate, and preview. No database operation is performed.
Stop the API and repeat: a visible unavailable/invalid-result message replaces the
prediction; policy calculation remains separately labeled. Residents/responders
cannot invoke this POST action. Use existing authorized allocation review and Hub
links to test the actual human workflows, preferably in a disposable database copy.

Verification commands:

```powershell
python tests/ui_sandbox.py --label preparedness
python tests/test_ml_client.py --live
# Serve the returned disposable sandbox on a separate port, then run:
# <Playwright-python> tests/test_ui_browser.py --sandbox <sandbox-path> --url http://127.0.0.1:8012
```

Results on 2026-11-06: browser suite **58 passed, 0 failed** (all four roles, five
tabs, mobile widths down to 320px, CSRF/invalid IDs, prediction denial, claim/report
workflows and no JS exceptions). Focused PHP suites: **110 role / 22 report /
54 relief hub / 24 oversight** assertions passed. **14 new integration checks** and client
failure tests pass; the real API sample (20 households/80 people, Flood/High/High,
urgent=1) returned High, confidence 50%, probabilities 0.30/0.50/0.20. This is a test
input, not barangay evidence. Existing main-QA Purok 6/8 assertions still fail.
The route prober now uses the production-style autoloader, resolving its missing
model-class load errors. QA used disposable copies; model hashes and the live
database's logical dump matched the pre-test copies.

## Safe Google Colab Decision Tree retraining

Do not run the existing demo trainers over active artifacts. The optional
`ml/colab_train_preparedness.py` trains a **separate Decision Tree candidate** using
the same six-feature Python API contract. It does not replace the PHP JSON tree or
the supplied Random Forest. Only load trusted joblib files (deserialization executes code).

1. Obtain authorized, privacy-minimized original-source reconciliation and expert
   priority labels. Existing synthetic CSVs and inferred Basyang migration rows are
   not an official training dataset. Do not manufacture labels to meet a class quota.
2. In Colab install Python-compatible `scikit-learn==1.6.1`, pandas, numpy and joblib.
   Upload the trainer and a reconciled CSV containing the six input columns plus
   `event_id,priority_label,affected_barangay,data_source,is_synthetic,is_verified,source_reconciled`.
   Only non-synthetic, verified, reconciled Hinaplanon records are accepted. API labels
   must be independently reviewed Critical/High/Moderate/Low; native Urgent/Medium
   labels are not silently relabeled.
3. Run `!python colab_train_preparedness.py reconciled.csv --output preparedness_decision_tree_candidate.joblib`.
   The trainer refuses missing fields, invalid numbers, duplicate feature/label
   rows and insufficient evidence (<50 rows, <5 events, <2 classes). Those minimums
   are guardrails, not proof of adequacy. Keep the entire event in one split;
   preprocessing fits on training rows only. Missing holdout class coverage fails
   instead of trying favorable random seeds.
4. Review accuracy, macro precision/recall/F1, confusion matrix and limitations in
   the emitted `.metadata.json`, including training date, model/dataset hashes and
   event-group split details. External/temporal validation and expert review are
   still required; do not present holdout scores as operational accuracy.
5. Download candidate and metadata, archive them separately, and test in a disposable
   deployment using `HINAPLANON_MODEL_PATH` pointing to the candidate. Never overwrite
   `hinaplanon_random_forest.joblib`, `active_model.json` or `active_purok_model.json`.
   Startup validates feature/classes/sklearn compatibility. Keep original metadata
   alongside the artifact; the current API does not publish candidate training dates.
6. No retraining was performed during this implementation because the available
   records do not establish a reconciled official training set.

## Files changed

New: `config/preparedness.php`, `src/Models/PreparednessOverview.php`,
`src/ML/PreparednessAnalysis.php`, `src/ML/PreparednessQuantityPlan.php`,
`views/preparedness/management.php`, `ml/colab_train_preparedness.py`,
`tests/test_preparedness_management.php`, `tests/test_ml_client.py`, this document.

Updated: `src/Controllers/PreparednessController.php`,
`src/Controllers/RecommendationController.php`, `src/ML/ColabModelClient.php`,
`views/layouts/main.php`, `public/index.php`, `public/assets/css/design-system.css`,
`hinaplanon_colab_bridge/start-api.ps1`, `hinaplanon_colab_bridge/verify_bridge.py`,
`hinaplanon_colab_bridge/README.md`, `tests/ui_sandbox.py`, `tests/test_ui_browser.py`,
`tests/test_feature_removal_roles.php`, `tests/probe_routes.php`.

Pre-edit checkpoint: `backup_pre_preparedness_20261106_002204/source.zip`.
Unrelated existing database/session/environment changes were not reverted or committed.
