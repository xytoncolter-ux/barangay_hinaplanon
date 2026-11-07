"""Isolated PHP HTTP-client checks. HTTP fixtures are test-only, never application data."""
import argparse
import json
import os
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FEATURES = dict(disaster_type='Flood', severity_level='High', purok_risk_level='High',
                is_urgent=1, affected_households=20, affected_population=80)
GOOD = dict(predicted_priority='High', confidence=80, probabilities={'High':.8,'Moderate':.2},
            model_source='colab_sklearn_candidate', exploratory=True, model_sha256='a'*64,feature_warnings=[])
state = {'mode':'good', 'authorized':False}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        state['authorized'] = self.headers.get('Authorization') == 'Bearer test-only-token'
        request = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        assert set(request) == set(FEATURES)
        mode = state['mode']
        if mode == 'slow':
            time.sleep(4)
        result = {**GOOD}
        if mode == 'probabilities':
            result['probabilities'] = {'High':.1,'Moderate':.1}
        body = json.dumps(result).encode()
        if mode == 'json':
            body=b'not JSON'
        if mode == 'oversized':
            body=b'x'*70000
        self.send_response(503 if mode == 'missing-model' else 302 if mode == 'redirect' else 200)
        if mode == 'redirect':
            self.send_header('Location','http://127.0.0.1:9/never-follow')
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError,ConnectionAbortedError):
            pass


def call(url, features=FEATURES, enabled='1'):
    code = "require 'src/ML/ColabModelClient.php'; $r=App\\ML\\ColabModelClient::predict(json_decode($argv[1],true)); echo json_encode(['result'=>$r,'error'=>App\\ML\\ColabModelClient::lastError()]);"
    env={**os.environ,'HINAPLANON_COLAB_MODEL_ENABLED':enabled,'HINAPLANON_ML_API_BASE_URL':url,'HINAPLANON_ML_API_TOKEN':'test-only-token'}
    proc=subprocess.run(['php','-r',code,json.dumps(features)],cwd=ROOT,env=env,capture_output=True,text=True,timeout=10)
    if proc.returncode:
        raise AssertionError(proc.stderr)
    return json.loads(proc.stdout)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--live',action='store_true')
    args=parser.parse_args()
    with ThreadingHTTPServer(('127.0.0.1',0),Handler) as server:
        threading.Thread(target=server.serve_forever,daemon=True).start()
        base=f'http://127.0.0.1:{server.server_port}'
        assert call(base)['result']['predicted_priority']=='High' and state['authorized']
        print('PASS valid response and backend-only bearer header')
        for mode in ['json','probabilities','missing-model','redirect','oversized','slow']:
            state['mode']=mode
            assert call(base)['result'] is None,mode
            print('PASS gracefully rejects',mode)
        assert call('http://127.0.0.1:9')['result'] is None
        print('PASS unavailable service')
        assert call('http://example.com')['result'] is None
        print('PASS remote plaintext URL rejected')
        assert call(base,{**FEATURES,'affected_households':-1})['result'] is None
        assert call(base,{**FEATURES,'is_urgent':2})['result'] is None
        assert call(base,{k:v for k,v in FEATURES.items() if k!='severity_level'})['result'] is None
        print('PASS negative, invalid and missing features rejected')
        assert call(base,enabled='0')['result'] is None
        print('PASS disabled opt-in')
        server.shutdown()
    if args.live:
        result=call('http://127.0.0.1:8001')['result']
        assert result is not None,'Live API did not return a valid prediction'
        print('PASS actual FastAPI priority/confidence/probabilities:',json.dumps(result))


if __name__=='__main__':
    main()
