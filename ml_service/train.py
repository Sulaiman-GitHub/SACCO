"""
SACCO Credit Risk Model Training & Evaluation Pipeline.

Trains and rigorously compares three candidate algorithms:
1. Logistic Regression (interpretable baseline)
2. Random Forest Classifier
3. Gradient Boosting Classifier (HistGradientBoosting / GradientBoosting)

Evaluates on imbalanced default metrics (AUC, Recall, Precision, F1, Brier Score)
and exports the champion model pipeline to ml_service/model/model.joblib.
"""

import json
import os
import joblib
import numpy as np
import pandas as pd
from sklearn.base import clone
from sklearn.ensemble import GradientBoostingClassifier, RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    average_precision_score,
    brier_score_loss,
    confusion_matrix,
    f1_score,
    precision_score,
    recall_score,
    roc_auc_score,
)
from sklearn.model_selection import StratifiedKFold, cross_val_predict, cross_val_score, train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler

FEATURE_NAMES = [
    'contribution_frequency',
    'contribution_consistency',
    'savings_balance',
    'tenure_months',
    'past_loans_count',
    'past_default_count',
    'on_time_repayment_rate',
    'loan_to_savings_ratio',
]

def load_or_generate_data(data_path: str) -> pd.DataFrame:
    if os.path.exists(data_path):
        print(f"Loading dataset from: {data_path}")
        return pd.read_csv(data_path)
    
    print("Dataset file not found. Generating synthetic dataset...")
    # Import local generator
    from ml_service.data.generate_dataset import generate_sacco_dataset
    df = generate_sacco_dataset(num_samples=6000, random_state=42)
    os.makedirs(os.path.dirname(data_path), exist_ok=True)
    df.to_csv(data_path, index=False)
    return df

def fit_platt_calibration(pipeline, X: pd.DataFrame, y: pd.Series) -> dict:
    """
    Fit a Platt (sigmoid) calibration that maps the champion's raw score to a probability.

    The candidates are trained with class_weight='balanced', which inflates raw probabilities well
    above the true default rate. The raw score is still useful for ranking (and drives the SACCO rule
    engine thresholds), but it must not be shown as a likelihood. The calibration is fitted on
    out-of-fold predictions, so it is not tuned on data the model has already seen.

    Calibrated probability = sigmoid(slope * logit(raw) + intercept).
    """
    cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
    raw = cross_val_predict(clone(pipeline), X, y, cv=cv, method='predict_proba')[:, 1]
    raw = np.clip(raw, 1e-6, 1 - 1e-6)
    logit = np.log(raw / (1 - raw)).reshape(-1, 1)

    platt = LogisticRegression(C=1e6, max_iter=1000).fit(logit, y)
    slope = float(platt.coef_[0][0])
    intercept = float(platt.intercept_[0])
    calibrated = 1.0 / (1.0 + np.exp(-(slope * logit.ravel() + intercept)))

    return {
        'method': 'platt',
        'slope': round(slope, 6),
        'intercept': round(intercept, 6),
        'brier_before': round(float(brier_score_loss(y, raw)), 4),
        'brier_after': round(float(brier_score_loss(y, calibrated)), 4),
        'mean_raw_probability': round(float(raw.mean()), 4),
        'observed_default_rate': round(float(y.mean()), 4),
    }

def train_and_evaluate():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    data_path = os.path.join(script_dir, 'data', 'sacco_credit_data.csv')
    model_dir = os.path.join(script_dir, 'model')
    os.makedirs(model_dir, exist_ok=True)

    df = load_or_generate_data(data_path)
    
    X = df[FEATURE_NAMES]
    y = df['defaulted']
    
    print(f"\nTotal instances: {len(df):,}")
    print(f"Default rate: {y.mean()*100:.2f}% ({y.sum()} defaults, {len(y)-y.sum()} non-defaults)")
    
    # 80/20 Stratified Split
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.20, stratify=y, random_state=42
    )
    
    # Candidate Model Pipelines
    candidates = {
        'logistic_regression': Pipeline([
            ('scaler', StandardScaler()),
            ('classifier', LogisticRegression(
                class_weight='balanced',
                max_iter=1000,
                random_state=42
            ))
        ]),
        'random_forest': Pipeline([
            ('classifier', RandomForestClassifier(
                n_estimators=150,
                max_depth=8,
                min_samples_split=6,
                min_samples_leaf=4,
                class_weight='balanced',
                random_state=42
            ))
        ]),
        'gradient_boosting': Pipeline([
            ('classifier', GradientBoostingClassifier(
                n_estimators=120,
                learning_rate=0.08,
                max_depth=4,
                min_samples_leaf=5,
                subsample=0.85,
                random_state=42
            ))
        ])
    }
    
    results = {}
    
    print("\n" + "="*80)
    print("MODEL EVALUATION COMPARISON (TEST SET)")
    print("="*80)
    print(f"{'Model':<22} | {'ROC-AUC':<8} | {'PR-AUC':<8} | {'Recall':<8} | {'Precision':<10} | {'F1':<6} | {'Brier':<6}")
    print("-" * 80)
    
    for name, pipeline in candidates.items():
        # Fit on training partition
        pipeline.fit(X_train, y_train)
        
        # Predict on holdout test partition
        y_pred = pipeline.predict(X_test)
        y_prob = pipeline.predict_proba(X_test)[:, 1]
        
        # Compute metrics
        roc_auc = float(roc_auc_score(y_test, y_prob))
        pr_auc = float(average_precision_score(y_test, y_prob))
        recall = float(recall_score(y_test, y_pred))
        precision = float(precision_score(y_test, y_pred))
        f1 = float(f1_score(y_test, y_pred))
        accuracy = float(accuracy_score(y_test, y_pred))
        brier = float(brier_score_loss(y_test, y_prob))
        cm = confusion_matrix(y_test, y_pred).tolist()
        
        # 5-fold cross-validated ROC-AUC on train set
        cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
        cv_scores = cross_val_score(pipeline, X_train, y_train, cv=cv, scoring='roc_auc')
        cv_mean = float(cv_scores.mean())
        cv_std = float(cv_scores.std())
        
        results[name] = {
            'roc_auc': round(roc_auc, 4),
            'pr_auc': round(pr_auc, 4),
            'recall': round(recall, 4),
            'precision': round(precision, 4),
            'f1': round(f1, 4),
            'accuracy': round(accuracy, 4),
            'brier_score': round(brier, 4),
            'cv_roc_auc_mean': round(cv_mean, 4),
            'cv_roc_auc_std': round(cv_std, 4),
            'confusion_matrix': cm
        }
        
        print(f"{name:<22} | {roc_auc:.4f}   | {pr_auc:.4f}   | {recall:.4f}   | {precision:.4f}     | {f1:.4f} | {brier:.4f}")
    
    print("=" * 80)
    
    # Model Selection:
    # Pick based on highest ROC-AUC combined with strong default recall
    # (Section 5.5: "pick based on evaluation metrics (precision, recall, AUC — not just accuracy)")
    champion_name = max(candidates.keys(), key=lambda k: results[k]['roc_auc'] + 0.5 * results[k]['recall'])
    champion_pipeline = candidates[champion_name]
    
    print(f"\nChampion Model Selected: '{champion_name}'")
    print(f"  Test ROC-AUC: {results[champion_name]['roc_auc']}")
    print(f"  Test Recall:  {results[champion_name]['recall']}")
    print(f"  Test F1:      {results[champion_name]['f1']}")
    
    # Retrain champion model on full dataset for maximum generalization capability in production
    print("\nFitting champion model on full dataset (6,000 instances)...")
    champion_pipeline.fit(X, y)
    
    # Save model artifact
    model_path = os.path.join(model_dir, 'model.joblib')
    joblib.dump(champion_pipeline, model_path)
    print(f"Exported model artifact to: {model_path}")
    
    # Extract Feature Importances or Coefficients
    feature_importance = {}
    clf = champion_pipeline.named_steps.get('classifier', champion_pipeline)
    if hasattr(clf, 'feature_importances_'):
        importances = clf.feature_importances_
        feature_importance = dict(zip(FEATURE_NAMES, [round(float(v), 4) for v in importances]))
    elif hasattr(clf, 'coef_'):
        coefs = clf.coef_[0]
        feature_importance = dict(zip(FEATURE_NAMES, [round(float(v), 4) for v in coefs]))
        
    calibration = fit_platt_calibration(champion_pipeline, X, y)
    print(
        f"Calibration (Platt, out-of-fold): Brier {calibration['brier_before']} -> {calibration['brier_after']}; "
        f"mean raw probability {calibration['mean_raw_probability']} vs observed default rate {calibration['observed_default_rate']}"
    )

    metadata = {
        'champion_model': champion_name,
        'features': FEATURE_NAMES,
        'feature_importance': feature_importance,
        'feature_baselines': {name: round(float(X[name].mean()), 4) for name in FEATURE_NAMES},
        'calibration': calibration,
        'metrics_comparison': results,
        'total_training_instances': len(df),
        'default_rate_pct': round(float(y.mean() * 100), 2),
        'version': '1.1.0'
    }
    
    metadata_path = os.path.join(model_dir, 'metrics.json')
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)
    print(f"Saved model metrics and metadata to: {metadata_path}")
    
    return champion_pipeline, metadata

if __name__ == '__main__':
    train_and_evaluate()
