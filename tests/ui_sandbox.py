"""Create a disposable PHP application copy for UI/workflow QA. Never seeds the live DB."""
import argparse
import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
from contextlib import closing
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SUITES = ['test_disaster_resource_requests.php', 'test_activity_calendar.php', 'test_preparedness_management.php', 'run_all_tests.php', 'test_feature_removal_roles.php', 'test_field_report_details.php',
          'test_relief_hub.php', 'test_relief_claims_oversight.php', 'probe_routes.php']


def snapshot(target):
    with closing(sqlite3.connect((ROOT / 'database/disaster_recommender.sqlite').as_uri() + '?mode=ro', uri=True)) as source:
        with closing(sqlite3.connect(target)) as destination:
            source.backup(destination)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--label', default='current')
    args = parser.parse_args()
    parent = Path(tempfile.gettempdir()) / 'opencode'
    parent.mkdir(exist_ok=True)
    sandbox = Path(tempfile.mkdtemp(prefix='padayon-ui-' + args.label + '-', dir=parent))
    for name in ['src', 'config', 'views', 'public', 'tests']:
        shutil.copytree(ROOT / name, sandbox / name)
    shutil.copytree(ROOT / 'ml/data', sandbox / 'ml/data')
    (sandbox / 'database/sessions').mkdir(parents=True)
    pristine = sandbox / 'database/pristine.sqlite'
    snapshot(pristine)
    db = sandbox / 'database/disaster_recommender.sqlite'
    # Migration is executed from its copied path, never from the working application's directory.
    migration = Path('database/migrations/20261106_disaster_resource_requests.php')
    (sandbox / migration.parent).mkdir(parents=True)
    shutil.copyfile(ROOT / migration, sandbox / migration)
    shutil.copyfile(pristine, db)
    subprocess.run(['php', str(migration)], cwd=sandbox,
                   env={**os.environ, 'DB_DRIVER':'sqlite'}, check=True, capture_output=True)
    shutil.copyfile(db, pristine)
    results = []
    for suite in SUITES:
        shutil.copyfile(pristine, db)
        environment = {**os.environ, 'DB_DRIVER': 'sqlite', 'HINAPLANON_COLAB_MODEL_ENABLED': '0'}
        process = subprocess.run(['php', 'tests/' + suite], cwd=sandbox, env=environment, capture_output=True, text=True,
                                 encoding='utf-8', errors='replace')
        output = process.stdout + process.stderr
        (sandbox / (suite + '.log')).write_text(output, encoding='utf-8')
        failures = [line.strip() for line in output.splitlines()
                    if '[FAIL]' in line or 'FAILED:' in line or 'Fatal error' in line]
        results.append({'suite': suite, 'exit': process.returncode, 'failures': failures,
                        'passes': output.count('[PASS]')})
    shutil.copyfile(pristine, db)  # Browser testing starts from the untouched snapshot.
    report = {'sandbox': str(sandbox), 'results': results}
    (sandbox / 'qa-results.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2))
    return 1 if any(item['failures'] or item['exit'] for item in results) else 0


if __name__ == '__main__':
    raise SystemExit(main())
