"""Offline/Colab candidate Decision Tree training. Never touches a database or active artifact.
Only use a human-reconciled CSV; the existing Basyang migration export is NOT suitable.
"""
import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.compose import ColumnTransformer
from sklearn.metrics import accuracy_score, precision_recall_fscore_support, confusion_matrix
from sklearn.model_selection import GroupShuffleSplit
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder
from sklearn.tree import DecisionTreeClassifier

FEATURES = ['disaster_type', 'severity_level', 'purok_risk_level', 'is_urgent',
            'affected_households', 'affected_population']
CATEGORICAL = FEATURES[:3]
CLASSES = ['Critical', 'High', 'Moderate', 'Low']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('csv', type=Path)
    parser.add_argument('--output', type=Path, default=Path('preparedness_decision_tree_candidate.joblib'))
    args = parser.parse_args()
    if args.output.exists() or args.output.with_suffix('.metadata.json').exists():
        raise SystemExit('Refusing to overwrite an existing model or metadata file.')
    if args.output.name in ['active_model.json', 'active_purok_model.json', 'hinaplanon_random_forest.joblib']:
        raise SystemExit('Use a separate candidate filename; preserve existing active artifacts.')
    df = pd.read_csv(args.csv)
    required = FEATURES + ['event_id', 'priority_label', 'affected_barangay', 'data_source',
                           'is_synthetic', 'is_verified', 'source_reconciled']
    missing = set(required) - set(df.columns)
    if missing:
        raise SystemExit(f'Missing provenance/features: {sorted(missing)}')
    if df[required].isna().any().any():
        raise SystemExit('Missing fields; reconcile original records rather than filling unknowns with zero.')
    if not df['affected_barangay'].str.strip().str.lower().isin(['hinaplanon', 'barangay hinaplanon']).all():
        raise SystemExit('Only Barangay Hinaplanon is permitted (not Upper Hinaplanon).')
    if not ((df.is_synthetic == 0) & (df.is_verified == 1) & (df.source_reconciled == 1)).all():
        raise SystemExit('Exclude synthetic, unverified and unreconciled rows before training.')
    if df.data_source.str.strip().eq('').any() or df.data_source.str.contains('Scenario Modeling|NGO Archive', regex=True).any():
        raise SystemExit('Original reconciled source references are required; legacy inferred imports are excluded.')
    if not df.priority_label.isin(CLASSES).all():
        raise SystemExit(f'Labels must be independently reviewed: {CLASSES}. Do not auto-map or fabricate labels.')
    for col in FEATURES[3:]:
        df[col] = pd.to_numeric(df[col], errors='raise')
        if not np.isfinite(df[col]).all() or (df[col] < 0).any():
            raise SystemExit(f'Invalid numeric values in {col}')
    if not df.is_urgent.isin([0, 1]).all():
        raise SystemExit('is_urgent must be 0 or 1.')
    if len(df) < 50 or df.event_id.nunique() < 5 or df.priority_label.nunique() < 2:
        raise SystemExit('Insufficient independently labeled evidence: need at least 50 rows, 5 events, 2 classes. This minimum does not establish validity.')
    # Exact duplicates across source events must be reconciled, not split into both sets.
    if df.duplicated(FEATURES + ['priority_label']).any():
        raise SystemExit('Duplicate feature/label rows require source reconciliation before splitting.')
    train, test = next(GroupShuffleSplit(n_splits=1, test_size=.25, random_state=42)
                       .split(df, df.priority_label, groups=df.event_id))
    if set(df.iloc[train].priority_label) != set(df.iloc[test].priority_label):
        raise SystemExit('Holdout lacks class coverage. Collect more events; do not search seeds for attractive metrics.')
    model = Pipeline([
        ('preprocessor', ColumnTransformer([
            ('categorical', OneHotEncoder(handle_unknown='ignore'), CATEGORICAL),
            ('numeric', 'passthrough', FEATURES[3:])])),
        ('classifier', DecisionTreeClassifier(max_depth=5, min_samples_split=4, random_state=42)),
    ])
    model.fit(df.iloc[train][FEATURES], df.iloc[train].priority_label)
    predictions = model.predict(df.iloc[test][FEATURES])
    precision, recall, f1, _ = precision_recall_fscore_support(df.iloc[test].priority_label, predictions, average='macro', zero_division=0)
    joblib.dump(model, args.output)
    metadata = {'algorithm':'DecisionTreeClassifier', 'trained_at':datetime.now(timezone.utc).isoformat(),
                'sklearn_version':sklearn.__version__, 'features':FEATURES,
                'model_sha256':hashlib.sha256(args.output.read_bytes()).hexdigest(),
                'dataset_sha256':hashlib.sha256(args.csv.read_bytes()).hexdigest(),
                'train_rows':len(train), 'test_rows':len(test), 'split':'event-group holdout, random_state=42',
                'accuracy':accuracy_score(df.iloc[test].priority_label, predictions),
                'macro_precision':precision, 'macro_recall':recall, 'macro_f1':f1,
                'confusion_labels':CLASSES, 'confusion_matrix':confusion_matrix(df.iloc[test].priority_label,predictions,labels=CLASSES).tolist(),
                'experimental':True, 'limitations':'No operational validation; no quantity prediction; event holdout is not external validation.'}
    args.output.with_suffix('.metadata.json').write_text(json.dumps(metadata,indent=2),encoding='utf-8')
    print(json.dumps(metadata,indent=2))


if __name__ == '__main__':
    main()
