"""
Test suite for SACCO ML Risk Scoring Service.
"""

import os
import pytest
from fastapi.testclient import TestClient
import numpy as np
import pandas as pd

from ml_service.data.generate_dataset import generate_sacco_dataset
from ml_service.train import train_and_evaluate, FEATURE_NAMES
from ml_service.app import app, load_model_and_metadata

@pytest.fixture(scope="session")
def trained_model():
    """Ensure model is trained and available before running endpoint tests."""
    train_and_evaluate()
    load_model_and_metadata()
    return True

@pytest.fixture
def client(trained_model):
    with TestClient(app) as test_client:
        yield test_client

def test_dataset_generator():
    df = generate_sacco_dataset(num_samples=200, random_state=123)
    
    # Assert all expected features exist
    for feature in FEATURE_NAMES:
        assert feature in df.columns
    assert 'defaulted' in df.columns
    
    # Assert no nulls
    assert df.isnull().sum().sum() == 0
    
    # Assert reasonable value boundaries
    assert (df['on_time_repayment_rate'] >= 0.0).all()
    assert (df['on_time_repayment_rate'] <= 1.0).all()
    assert (df['loan_to_savings_ratio'] > 0.0).all()
    assert set(df['defaulted'].unique()).issubset({0, 1})

def test_health_endpoint(client):
    response = client.get("/health")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "healthy"
    assert data["model_loaded"] is True
    assert "version" in data

def test_model_info_endpoint(client):
    response = client.get("/model-info")
    assert response.status_code == 200
    data = response.json()
    assert "champion_model" in data
    assert "metrics_comparison" in data
    assert "feature_importance" in data
    assert "logistic_regression" in data["metrics_comparison"]
    assert "random_forest" in data["metrics_comparison"]
    assert "gradient_boosting" in data["metrics_comparison"]

def test_score_low_risk_applicant(client):
    # Prime applicant: consistent saver, zero defaults, low leverage (1.2x), excellent tenure
    payload = {
        "contribution_frequency": 2.5,
        "contribution_consistency": 0.15,
        "savings_balance": 5000000.0,
        "tenure_months": 36,
        "past_loans_count": 4,
        "past_default_count": 0,
        "on_time_repayment_rate": 0.98,
        "loan_to_savings_ratio": 1.2
    }
    response = client.post("/score", json=payload)
    assert response.status_code == 200
    res = response.json()
    assert res["status"] == "success"
    assert 0.0 <= res["risk_score"] <= 100.0
    assert 0.0 <= res["default_probability"] <= 1.0
    assert res["risk_band"] in ["low", "medium"]
    assert res["risk_score"] < 40.0 # Should definitely be low/moderate risk

def test_score_high_risk_applicant(client):
    # Highly distressed applicant: prior defaults, very high leverage (7x), erratic savings, poor on-time rate
    payload = {
        "contribution_frequency": 0.3,
        "contribution_consistency": 1.80,
        "savings_balance": 200000.0,
        "tenure_months": 4,
        "past_loans_count": 2,
        "past_default_count": 2,
        "on_time_repayment_rate": 0.35,
        "loan_to_savings_ratio": 7.5
    }
    response = client.post("/score", json=payload)
    assert response.status_code == 200
    res = response.json()
    assert res["status"] == "success"
    assert res["risk_score"] >= 65.0
    assert res["risk_band"] == "high"
    # Calibrated probability is far below the raw ranking score, but still clearly elevated
    assert res["probability_calibrated"] is True
    assert res["default_probability"] > 0.30

LOW_RISK = {
    "contribution_frequency": 2.5, "contribution_consistency": 0.15, "savings_balance": 5000000.0,
    "tenure_months": 36, "past_loans_count": 4, "past_default_count": 0,
    "on_time_repayment_rate": 0.98, "loan_to_savings_ratio": 1.2,
}
HIGH_RISK = {
    "contribution_frequency": 0.3, "contribution_consistency": 1.80, "savings_balance": 200000.0,
    "tenure_months": 4, "past_loans_count": 2, "past_default_count": 2,
    "on_time_repayment_rate": 0.35, "loan_to_savings_ratio": 7.5,
}

def test_probability_is_calibrated_not_inflated(client):
    low = client.post("/score", json=LOW_RISK).json()
    high = client.post("/score", json=HIGH_RISK).json()

    # A strong applicant's probability must sit near or below the 7.9% base default rate,
    # not at the inflated raw score
    assert low["default_probability"] < 0.10
    assert low["default_probability"] < low["risk_score"] / 100.0
    # Calibration is monotonic: ordering by score matches ordering by probability
    assert high["default_probability"] > low["default_probability"]

def test_calibration_metadata_improves_brier_score(client):
    calibration = client.get("/model-info").json()["calibration"]

    assert calibration["method"] == "platt"
    assert calibration["brier_after"] < calibration["brier_before"]
    assert calibration["mean_raw_probability"] > calibration["observed_default_rate"]

def test_feature_contributions_come_from_the_model(client):
    res = client.post("/score", json=HIGH_RISK).json()
    contributions = res["feature_contributions"]

    assert set(contributions) == set(FEATURE_NAMES)
    # Prior defaults and leverage must raise this applicant's risk versus an average member
    assert contributions["past_default_count"] > 0
    assert contributions["loan_to_savings_ratio"] > 0
    # Strong savings habits pull a good applicant's risk down
    good = client.post("/score", json=LOW_RISK).json()["feature_contributions"]
    assert good["contribution_frequency"] < 0
    assert good["past_default_count"] <= 0

def test_feature_contribution_is_zero_at_the_baseline(client):
    baselines = client.get("/model-info").json()["feature_baselines"]
    payload = {name: baselines[name] for name in FEATURE_NAMES}
    payload["tenure_months"] = int(round(payload["tenure_months"]))
    payload["past_loans_count"] = int(round(payload["past_loans_count"]))
    payload["past_default_count"] = int(round(payload["past_default_count"]))

    contributions = client.post("/score", json=payload).json()["feature_contributions"]

    # A near-average member has small effects for every feature
    assert all(abs(v) < 5.0 for v in contributions.values())

def test_score_validation_error(client):
    # Invalid on_time_repayment_rate > 1.0
    payload = {
        "contribution_frequency": 1.0,
        "contribution_consistency": 0.5,
        "savings_balance": 100000.0,
        "tenure_months": 10,
        "past_loans_count": 1,
        "past_default_count": 0,
        "on_time_repayment_rate": 1.5, # Invalid: must be <= 1.0
        "loan_to_savings_ratio": 2.0
    }
    response = client.post("/score", json=payload)
    assert response.status_code == 422
