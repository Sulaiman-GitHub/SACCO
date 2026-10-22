"""
FastAPI Microservice for SACCO ML Credit Risk Scoring.

Exposes:
- GET  /health      : Service health and model status
- GET  /model-info  : Champion model metadata and training evaluation metrics
- POST /score       : Evaluates applicant feature vector and returns risk score (0-100)
"""

import json
import os
import sys
from contextlib import asynccontextmanager
from typing import Dict, List, Optional

_root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _root_dir not in sys.path:
    sys.path.insert(0, _root_dir)
import joblib
import numpy as np
import pandas as pd
from fastapi import FastAPI, HTTPException, status
from pydantic import BaseModel, Field

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

# Model and metadata global state
model = None
metadata: Dict = {}

def get_model_path() -> str:
    script_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(script_dir, 'model', 'model.joblib')

def get_metadata_path() -> str:
    script_dir = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(script_dir, 'model', 'metrics.json')

def load_model_and_metadata():
    global model, metadata
    model_path = get_model_path()
    meta_path = get_metadata_path()
    
    if not os.path.exists(model_path):
        # Auto-train if model artifact not yet generated
        print("Model artifact not found. Triggering automated training pipeline...")
        from ml_service.train import train_and_evaluate
        model, metadata = train_and_evaluate()
        return

    try:
        model = joblib.load(model_path)
        print(f"Loaded ML model from: {model_path}")
    except Exception as e:
        print(f"Error loading model artifact: {e}")
        model = None

    if os.path.exists(meta_path):
        try:
            with open(meta_path, 'r') as f:
                metadata = json.load(f)
        except Exception as e:
            print(f"Error reading metadata: {e}")
            metadata = {}

@asynccontextmanager
async def lifespan(app: FastAPI):
    load_model_and_metadata()
    yield

app = FastAPI(
    title="SACCO Credit Risk Scoring Service",
    description="Machine Learning Risk Assessment API for SACCOs in Uganda",
    version="1.0.0",
    lifespan=lifespan
)

class ScoreRequest(BaseModel):
    contribution_frequency: float = Field(..., ge=0.0, description="Deposits per month (e.g. 1.5)")
    contribution_consistency: float = Field(..., ge=0.0, description="CV of deposits (std/mean, e.g. 0.35)")
    savings_balance: float = Field(..., ge=0.0, description="Current savings balance in UGX (e.g. 1500000)")
    tenure_months: int = Field(..., ge=0, description="Months as active member (e.g. 18)")
    past_loans_count: int = Field(..., ge=0, description="Past closed loans (e.g. 2)")
    past_default_count: int = Field(..., ge=0, description="Past defaults (e.g. 0)")
    on_time_repayment_rate: float = Field(..., ge=0.0, le=1.0, description="Repayments on time (0.0 - 1.0)")
    loan_to_savings_ratio: float = Field(..., ge=0.0, description="Loan principal / savings (e.g. 2.0)")

    model_config = {
        "json_schema_extra": {
            "example": {
                "contribution_frequency": 2.0,
                "contribution_consistency": 0.25,
                "savings_balance": 2500000.0,
                "tenure_months": 24,
                "past_loans_count": 3,
                "past_default_count": 0,
                "on_time_repayment_rate": 0.95,
                "loan_to_savings_ratio": 1.6
            }
        }
    }

class ScoreResponse(BaseModel):
    status: str = "success"
    risk_score: float = Field(..., description="Ranking score from 0.0 (safest) to 100.0 (highest risk). Not a probability: the model is class-weighted, so it overstates likelihood.")
    default_probability: float = Field(..., description="Estimated default probability between 0.0 and 1.0, calibrated when probability_calibrated is true")
    probability_calibrated: bool = Field(False, description="True when default_probability is calibrated to the training default rate")
    risk_band: str = Field(..., description="'low' (<35), 'medium' (35-65), or 'high' (>=65)")
    model_version: str
    champion_algorithm: str
    feature_contributions: Dict[str, float]

@app.get("/health", status_code=status.HTTP_200_OK)
def health_check():
    is_ready = model is not None
    return {
        "status": "healthy" if is_ready else "degraded",
        "model_loaded": is_ready,
        "champion_model": metadata.get("champion_model", "unknown"),
        "version": metadata.get("version", "1.0.0"),
        "features": FEATURE_NAMES
    }

@app.get("/model-info", status_code=status.HTTP_200_OK)
def model_info():
    if not metadata:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Model metadata is currently unavailable"
        )
    return metadata

def calibrated_probability(raw_probability: float) -> Optional[float]:
    """
    Map the model's raw score to a calibrated default probability using the Platt parameters saved at
    training time. Returns None if the loaded model has no calibration data (older artifact).
    """
    calibration = metadata.get('calibration')
    if not calibration:
        return None

    raw = min(max(raw_probability, 1e-6), 1 - 1e-6)
    logit = float(np.log(raw / (1 - raw)))
    return float(1.0 / (1.0 + np.exp(-(calibration['slope'] * logit + calibration['intercept']))))

def calculate_feature_contributions(row: pd.DataFrame, raw_probability: float) -> Dict[str, float]:
    """
    Per-feature effect on the risk score, in score points (0-100 scale).

    Each value is the model's own score for this applicant minus its score when that one feature is
    replaced by the training-data average. Positive values pushed this applicant's score up (more
    risk) relative to an average member, negative values pulled it down. It works for any model
    type, but the values are not additive: interactions and the nonlinearity of the model mean they
    need not sum to the total score.
    """
    baselines = metadata.get('feature_baselines')
    if not baselines:
        return {}

    variants = []
    for name in FEATURE_NAMES:
        variant = row.iloc[0].copy()
        variant[name] = baselines[name]
        variants.append(variant)

    baseline_probs = model.predict_proba(pd.DataFrame(variants)[FEATURE_NAMES])[:, 1]

    return {
        name: round((raw_probability - float(p)) * 100.0, 2)
        for name, p in zip(FEATURE_NAMES, baseline_probs)
    }

@app.post("/score", response_model=ScoreResponse, status_code=status.HTTP_200_OK)
def score_applicant(payload: ScoreRequest):
    global model, metadata
    
    if model is None:
        load_model_and_metadata()
        if model is None:
            raise HTTPException(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                detail="Risk scoring model is not initialized or trained."
            )
            
    input_data = pd.DataFrame([{
        'contribution_frequency': payload.contribution_frequency,
        'contribution_consistency': payload.contribution_consistency,
        'savings_balance': payload.savings_balance,
        'tenure_months': payload.tenure_months,
        'past_loans_count': payload.past_loans_count,
        'past_default_count': payload.past_default_count,
        'on_time_repayment_rate': payload.on_time_repayment_rate,
        'loan_to_savings_ratio': payload.loan_to_savings_ratio,
    }])
    
    try:
        prob = float(model.predict_proba(input_data)[0, 1])
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error executing scoring inference: {str(e)}"
        )
        
    risk_score = round(prob * 100.0, 2)
    
    if risk_score < 35.0:
        risk_band = "low"
    elif risk_score < 65.0:
        risk_band = "medium"
    else:
        risk_band = "high"
        
    contributions = calculate_feature_contributions(input_data, prob)
    calibrated = calibrated_probability(prob)

    return ScoreResponse(
        status="success",
        risk_score=risk_score,
        default_probability=round(calibrated if calibrated is not None else prob, 4),
        probability_calibrated=calibrated is not None,
        risk_band=risk_band,
        model_version=metadata.get("version", "1.0.0"),
        champion_algorithm=metadata.get("champion_model", "unknown"),
        feature_contributions=contributions
    )
