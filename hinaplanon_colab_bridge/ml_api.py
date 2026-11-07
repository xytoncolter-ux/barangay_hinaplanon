"""Local-only API for an explicitly reviewed, Colab-trained scikit-learn pipeline.
Never load untrusted joblib files. This is a development bridge, not a public endpoint.
"""
import hashlib
import json
import math
import logging
import os
import warnings
from contextlib import asynccontextmanager
from pathlib import Path
import joblib
import numpy as np
import pandas as pd
import sklearn
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, ConfigDict, Field
from sklearn.exceptions import InconsistentVersionWarning

BASE = Path(__file__).resolve().parent
MODEL_PATH = Path(os.environ.get('HINAPLANON_MODEL_PATH', str(BASE / 'hinaplanon_random_forest.joblib')))
FEATURES = ['disaster_type', 'severity_level', 'is_urgent', 'purok_risk_level', 'affected_households', 'affected_population']
model = None
metadata = {}
ALLOWED_CLASSES = {'Critical', 'High', 'Moderate', 'Low'}
logger = logging.getLogger('uvicorn.error')

class PredictionRequest(BaseModel):
    model_config = ConfigDict(extra='forbid', allow_inf_nan=False)
    disaster_type: str = Field(min_length=1)
    severity_level: str = Field(min_length=1)
    is_urgent: int = Field(ge=0, le=1, strict=True)
    purok_risk_level: str = Field(min_length=1)
    affected_households: float = Field(ge=0)
    affected_population: float = Field(ge=0)

@asynccontextmanager
async def lifespan(app):
    global model, metadata
    if not MODEL_PATH.is_file():
        raise RuntimeError(f'Model file not found: {MODEL_PATH}')
    # A mismatched pickle may load yet produce unsupported results: fail at startup.
    with warnings.catch_warnings():
        warnings.simplefilter('error', InconsistentVersionWarning)
        candidate = joblib.load(MODEL_PATH)
    if not hasattr(candidate, 'predict') or not hasattr(candidate, 'predict_proba'):
        raise RuntimeError('Expected a fitted scikit-learn classification pipeline')
    features = list(getattr(candidate, 'feature_names_in_', []))
    if len(features) != len(FEATURES) or set(features) != set(FEATURES):
        raise RuntimeError(f'Incompatible model features: expected {FEATURES}, got {features}')
    classes = [str(c) for c in candidate.classes_]
    if len(classes) < 2 or not set(classes).issubset(ALLOWED_CLASSES):
        raise RuntimeError(f'Incompatible priority classes: {classes}')
    preprocessor = candidate.named_steps['preprocessor']
    categorical = preprocessor.named_transformers_['categorical']
    category_features = next(cols for name, _, cols in preprocessor.transformers_ if name == 'categorical')
    categories = {str(key): [str(v) for v in values]
                  for key, values in zip(category_features, categorical.categories_)}
    notes = []
    missing = sorted(ALLOWED_CLASSES - set(classes))
    if missing:
        notes.append(f'Model cannot predict absent classes: {", ".join(missing)}')
    notes.append('Exploratory candidate; probabilities are not validated operational accuracy.')
    metadata = {'features': features, 'classes': classes, 'categories': categories,
                'sklearn_version': sklearn.__version__, 'exploratory': True,
                'model_sha256': hashlib.sha256(MODEL_PATH.read_bytes()).hexdigest(),
                'warnings': notes}
    model = candidate
    # Exercise the actual fitted pipeline before announcing a healthy service.
    predict(PredictionRequest(disaster_type='Flood', severity_level='High', is_urgent=1,
                             purok_risk_level='High', affected_households=100, affected_population=400))
    for note in notes:
        logger.warning(note)
    yield
    model = None

app = FastAPI(title='Hinaplanon ML Local Bridge', lifespan=lifespan)


@app.post('/predict-decision-tree')
def predict_decision_tree(payload: PredictionRequest):
    """Serve the existing PHP CART JSON artifact without retraining or replacing the RF."""
    path = Path(os.environ.get('HINAPLANON_DECISION_TREE_PATH', str(BASE.parent / 'ml/data/active_model.json')))
    if not path.is_file():
        raise HTTPException(status_code=503, detail='Decision Tree artifact unavailable')
    values = payload.model_dump()
    if any(not float(values[k]).is_integer() or values[k] > 100_000_000
           for k in ['affected_households', 'affected_population']):
        raise HTTPException(status_code=422, detail='Impact inputs must be whole counts within bounds')
    try:
        raw = path.read_bytes()
        artifact = json.loads(raw)
        if artifact.get('algorithm') != 'DecisionTreeClassifier (CART/Gini)' or set(artifact['feature_names']) != set(FEATURES):
            raise ValueError('Incompatible Decision Tree artifact')
        node = artifact['tree']
        steps = []
        for _ in range(100):
            if node['type'] == 'leaf':
                break
            if node['type'] != 'split' or node['feature'] not in FEATURES:
                raise ValueError('Invalid tree split')
            feature = node['feature']
            value = values[feature]
            if node['is_numeric']:
                threshold = float(node['threshold'])
                left = float(value) <= threshold
                steps.append(f'{feature} {"<=" if left else ">"} {threshold:g}')
            else:
                target = str(node['threshold'])
                left = str(value) == target
                steps.append(f"{feature} {'==' if left else '!='} '{target}'")
            node = node['left' if left else 'right']
        else:
            raise ValueError('Tree depth limit exceeded')
        allowed = {'Urgent', 'High', 'Medium', 'Low'}
        label = node['predicted_class']
        probabilities = {str(k): float(v) for k, v in node['probabilities'].items()}
        if label not in allowed or label not in probabilities or not set(probabilities).issubset(allowed):
            raise ValueError('Invalid class labels')
        if any(not math.isfinite(p) or p < 0 or p > 1 for p in probabilities.values()) or abs(sum(probabilities.values()) - 1) > .001:
            raise ValueError('Invalid leaf probabilities')
        digest = hashlib.sha256(raw).hexdigest()
        return {'predicted_priority': label, 'confidence': round(probabilities[label] * 100, 2),
                'probabilities': probabilities, 'decision_path': steps,
                'algorithm': artifact['algorithm'], 'model_source': 'native_decision_tree_api',
                'model_version': 'CART-' + digest[:12], 'model_sha256': digest,
                'artifact_saved_at': artifact.get('saved_at'), 'training_date': None, 'exploratory': True,
                'feature_warnings': ['Existing artifact training provenance and operational accuracy are not independently validated.']}
    except (ValueError, KeyError, TypeError, OSError) as exc:
        logger.warning('Decision Tree artifact unavailable or invalid: %s', type(exc).__name__)
        raise HTTPException(status_code=503, detail='Decision Tree artifact invalid or unavailable') from exc

@app.get('/health')
def health():
    return {'ok': model is not None, 'model_file': MODEL_PATH.name,
            'model_loaded': model is not None, **metadata}

@app.post('/predict')
def predict(payload: PredictionRequest):
    if model is None:
        raise HTTPException(status_code=503, detail='Model not loaded: place the .joblib file next to ml_api.py and restart')
    try:
        values = payload.model_dump()
        frame = pd.DataFrame([values], columns=metadata['features'])
        cls = str(model.predict(frame)[0])
        if cls not in metadata['classes']:
            raise ValueError(f'Unexpected priority class: {cls}')
        probs = np.asarray(model.predict_proba(frame)[0], dtype=float)
        labels = metadata['classes']
        if (len(probs) != len(labels) or not np.isfinite(probs).all()
                or (probs < 0).any() or (probs > 1).any() or not np.isclose(probs.sum(), 1)):
            raise ValueError('Invalid probability distribution from model')
        probabilities = {key: round(float(value), 5) for key, value in zip(labels, probs)}
        feature_warnings = [f'{key}={values[key]!r} is outside trained categories; encoded as unknown'
                            for key, known in metadata['categories'].items() if values[key] not in known]
        return {'predicted_priority': cls, 'confidence': round(float(probs[labels.index(cls)]) * 100, 1),
                'probabilities': probabilities, 'model_source': 'colab_sklearn_candidate',
                'model_sha256': metadata['model_sha256'], 'exploratory': True,
                'feature_warnings': feature_warnings}
    except Exception as exc:
        raise HTTPException(status_code=422, detail=f'Prediction failed: {exc}')
