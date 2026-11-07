"""Real HTTP and PHP assessment tests; database writes are confined to temporary backups."""
import argparse
import hashlib
import json
import math
import os
import sqlite3
import subprocess
import tempfile
from contextlib import closing
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, Request, build_opener

import joblib
import pandas as pd

BASE = Path(__file__).resolve().parent
ROOT = BASE.parent
OPENER = build_opener(ProxyHandler({}))


def request(path, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = Request(os.environ.get('HINAPLANON_ML_API_BASE_URL', 'http://127.0.0.1:8001').rstrip('/') + path, data=data, headers={'Content-Type': 'application/json'})
    try:
        with OPENER.open(req, timeout=10) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        return error.code, json.load(error)


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify_api():
    status, health = request('/health')
    check(status == 200 and health['ok'] and health['model_loaded'], 'API is not healthy')
    check(health['model_sha256'] == digest(BASE / 'hinaplanon_random_forest.joblib'), 'API loaded a different model')
    model = joblib.load(BASE / 'hinaplanon_random_forest.joblib')
    samples = [
        dict(disaster_type='Flood', severity_level=severity, is_urgent=urgent,
             purok_risk_level=risk, affected_households=hh, affected_population=pop)
        for severity, urgent, risk, hh, pop in [
            ('Critical', 1, 'High', 395, 1580), ('High', 1, 'High', 100, 400),
            ('Medium', 0, 'Low', 10, 40), ('Moderate', 0, 'Medium', 110, 450),
            ('High', 0, 'Pending Verification', 0, 0)]
    ]
    for sample in samples:
        status, result = request('/predict', sample)
        frame = pd.DataFrame([sample], columns=list(model.feature_names_in_))
        expected = str(model.predict(frame)[0])
        probabilities = model.predict_proba(frame)[0]
        expected_confidence = round(float(probabilities[list(model.classes_).index(expected)]) * 100, 1)
        check(status == 200 and result['predicted_priority'] == expected, f'API/direct prediction mismatch: {result}')
        check(result['confidence'] == expected_confidence, 'API/direct confidence mismatch')
        check(math.isclose(sum(result['probabilities'].values()), 1, abs_tol=0.001), 'Probabilities do not sum to 1')
        check(set(result['probabilities']) == set(model.classes_), 'Probability labels are incompatible')
        if sample['severity_level'] == 'Moderate':
            check(bool(result['feature_warnings']), 'Unseen severity was not reported')
        print('PASS prediction:', json.dumps({'input': sample, 'result': result}))
    for changes in [{'affected_population': -1}, {'is_urgent': 2}, {'severity_level': ''}, {'extra': 1}]:
        status, _ = request('/predict', {**samples[0], **changes})
        check(status == 422, f'Invalid request was not rejected: {changes}')
    status, _ = request('/predict', {'disaster_type': 'Flood'})
    check(status == 422, 'Missing features were not rejected')
    print('PASS invalid requests: 5 rejected with HTTP 422')
    print('Model compatibility:', json.dumps(health))


def verify_php(modes):
    source = ROOT / 'database/disaster_recommender.sqlite'
    # sqlite backup obtains a coherent snapshot without writing to the source database.
    temp_root = Path(tempfile.gettempdir()) / 'opencode'
    temp_root.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='hinaplanon-bridge-', dir=temp_root) as temp:
        snapshot = Path(temp) / 'snapshot.sqlite'
        with closing(sqlite3.connect(source.as_uri() + '?mode=ro', uri=True)) as original:
            with closing(sqlite3.connect(snapshot)) as backup:
                original.backup(backup)
        for mode in modes:
            target = Path(temp) / 'bridge-test.sqlite'
            target.write_bytes(snapshot.read_bytes())
            process = subprocess.run(['php', str(BASE / 'test_assessment.php'), str(target), mode],
                                     cwd=ROOT, capture_output=True, text=True)
            if process.stderr:
                print(process.stderr.strip())
            check(process.returncode == 0, f'PHP {mode} failed: {process.stdout} {process.stderr}')
            print('PASS PHP integration:', process.stdout.strip())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--api-down', action='store_true', help='Verify actual outage fallbacks; stop API first')
    args = parser.parse_args()
    protected = [BASE / 'hinaplanon_random_forest.joblib', ROOT / 'ml/data/active_model.json',
                 ROOT / 'ml/data/active_purok_model.json', ROOT / 'database/disaster_recommender.sqlite']
    before = {str(path): digest(path) for path in protected}
    try:
        if args.api_down:
            try:
                request('/health')
            except URLError:
                pass
            else:
                raise AssertionError('API is still reachable; stop it before --api-down')
            verify_php(['down-native', 'down-rules'])
        else:
            verify_api()
            verify_php(['colab', 'colab-only', 'native', 'rules', 'forced-rules'])
    finally:
        check(before == {str(path): digest(path) for path in protected}, 'Working database or supplied models changed')
    print('PASS protected files unchanged; all requested bridge checks passed')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit(f'FAILED: {error}') from error
