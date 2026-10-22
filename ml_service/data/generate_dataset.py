"""
Synthetic SACCO Credit Risk Dataset Generator.

Generates realistic loan applicant behavior and repayment outcome data modeled
on Ugandan Savings and Credit Cooperative Organizations (SACCOs).

Features match Section 7 of SYSTEM_ARCHITECTURE.md:
1. contribution_frequency: Average deposits per month (0.1 to 5.0)
2. contribution_consistency: Coefficient of variation (CV) of deposit amounts (std/mean, 0.05 to 2.5)
3. savings_balance: Current savings balance in UGX (50,000 to 25,000,000)
4. tenure_months: Duration of membership in months (1 to 120)
5. past_loans_count: Previous completed loans (0 to 12)
6. past_default_count: Previous default occurrences (0 to 4)
7. on_time_repayment_rate: Fraction of installments paid on time (0.0 to 1.0)
8. loan_to_savings_ratio: Requested loan principal / savings balance (0.2 to 10.0)

Target:
- defaulted: Binary indicator (1 if loan defaulted >90 days overdue, 0 if repaid)
"""

import os
import numpy as np
import pandas as pd

def generate_sacco_dataset(num_samples: int = 6000, random_state: int = 42) -> pd.DataFrame:
    np.random.seed(random_state)
    
    # 1. Tenure in months (gamma distributed: average ~ 24 months, up to 10 years)
    tenure_months = np.random.gamma(shape=2.5, scale=10.0, size=num_samples).astype(int) + 1
    tenure_months = np.clip(tenure_months, 1, 120)
    
    # 2. Contribution frequency (deposits per month):
    # Active regular savers deposit 1.0 - 4.0 times/month; irregular 0.1 - 0.9
    is_regular_saver = np.random.binomial(n=1, p=0.72, size=num_samples)
    freq_regular = np.random.normal(loc=1.8, scale=0.6, size=num_samples)
    freq_irregular = np.random.uniform(low=0.1, high=0.8, size=num_samples)
    contribution_frequency = np.where(is_regular_saver == 1, freq_regular, freq_irregular)
    contribution_frequency = np.clip(np.round(contribution_frequency, 2), 0.1, 6.0)
    
    # 3. Contribution consistency (CV = std/mean):
    # Lower means steady predictable savings; high means volatile/erratic
    cv_regular = np.random.gamma(shape=2.0, scale=0.15, size=num_samples) # mean ~ 0.30
    cv_irregular = np.random.gamma(shape=3.0, scale=0.35, size=num_samples) # mean ~ 1.05
    contribution_consistency = np.where(is_regular_saver == 1, cv_regular, cv_irregular)
    contribution_consistency = np.clip(np.round(contribution_consistency, 3), 0.05, 3.0)
    
    # 4. Savings balance (in UGX)
    # Scales with tenure and regular savings
    base_savings = (tenure_months * contribution_frequency * np.random.uniform(50000, 250000, size=num_samples))
    savings_balance = np.clip(np.round(base_savings, -3), 50000.0, 50000000.0)
    
    # 5. Past loans count
    # Correlated with tenure (e.g. roughly 1 loan every 8-12 months for active borrowers)
    borrower_propensity = np.random.beta(a=2, b=3, size=num_samples)
    max_possible_loans = np.maximum(0, tenure_months // 6)
    past_loans_count = np.random.binomial(n=np.minimum(max_possible_loans, 10), p=borrower_propensity)
    
    # 6. Past defaults count
    # Conditional on having past loans
    default_proneness = np.random.beta(a=1, b=8, size=num_samples)
    past_default_count = np.zeros(num_samples, dtype=int)
    has_past_loans = past_loans_count > 0
    past_default_count[has_past_loans] = np.random.binomial(
        n=past_loans_count[has_past_loans],
        p=default_proneness[has_past_loans]
    )
    
    # 7. On-time repayment rate (0.0 to 1.0)
    # High for non-defaulters; heavily reduced if past defaults occurred
    on_time_base = np.random.beta(a=12, b=2, size=num_samples) # centered around 0.85 - 0.95
    penalty = (past_default_count / np.maximum(1, past_loans_count)) * 0.60
    on_time_repayment_rate = np.clip(np.round(on_time_base - penalty, 2), 0.0, 1.0)
    # For first-time borrowers with 0 past loans, set to neutral benchmark (1.0 default for clean slate)
    on_time_repayment_rate[past_loans_count == 0] = 1.0
    
    # 8. Loan to savings ratio (requested loan principal / savings)
    # SACCOs typically cap loans at 2x - 3x savings, but higher ratios occur (up to 8x)
    ratio_clean = np.random.gamma(shape=3.0, scale=0.6, size=num_samples) # mean ~ 1.8
    ratio_high_risk = np.random.uniform(low=2.5, high=8.5, size=num_samples)
    take_high_leverage = np.random.binomial(n=1, p=0.20, size=num_samples)
    loan_to_savings_ratio = np.where(take_high_leverage == 1, ratio_high_risk, ratio_clean)
    loan_to_savings_ratio = np.clip(np.round(loan_to_savings_ratio, 2), 0.2, 10.0)
    
    # Calculate Ground-Truth Default Log-Odds (Latent Credit Risk)
    # Positive weights increase default risk; negative weights decrease default risk
    log_odds = (
        - 2.80                                       # Base intercept (yields ~14% baseline default rate)
        + 1.85 * (past_default_count > 0)             # Prior default in SACCO is a major red flag
        + 0.90 * past_default_count                   # Multiple defaults compound risk
        + 0.65 * (loan_to_savings_ratio - 2.0)        # Leverage penalty above 2.0x savings
        + 0.85 * contribution_consistency             # Erratic savings variance increases default probability
        - 0.55 * contribution_frequency               # Frequent habitual savers default much less
        - 0.40 * np.log1p(tenure_months / 12.0)       # Membership loyalty / tenure buffer
        - 2.20 * (on_time_repayment_rate - 0.5)       # Proven repayment track record
        - 0.25 * np.log10(savings_balance / 100000.0) # Larger absolute savings cushion
        + np.random.normal(loc=0.0, scale=0.45, size=num_samples) # Idiosyncratic life shocks
    )
    
    # Convert log-odds to probability via logistic sigmoid
    default_prob = 1.0 / (1.0 + np.exp(-log_odds))
    default_prob = np.clip(default_prob, 0.01, 0.99)
    
    # Sample binary outcome
    defaulted = np.random.binomial(n=1, p=default_prob)
    
    df = pd.DataFrame({
        'contribution_frequency': contribution_frequency,
        'contribution_consistency': contribution_consistency,
        'savings_balance': savings_balance,
        'tenure_months': tenure_months,
        'past_loans_count': past_loans_count,
        'past_default_count': past_default_count,
        'on_time_repayment_rate': on_time_repayment_rate,
        'loan_to_savings_ratio': loan_to_savings_ratio,
        'defaulted': defaulted
    })
    
    return df

if __name__ == '__main__':
    script_dir = os.path.dirname(os.path.abspath(__file__))
    output_path = os.path.join(script_dir, 'sacco_credit_data.csv')
    
    print("Generating synthetic SACCO credit dataset...")
    df = generate_sacco_dataset(num_samples=6000, random_state=42)
    df.to_csv(output_path, index=False)
    
    default_rate = df['defaulted'].mean() * 100
    print(f"Dataset successfully generated at: {output_path}")
    print(f"Total records: {len(df):,}")
    print(f"Default rate: {default_rate:.2f}% (imbalanced realistic distribution)")
    print("\nFeature Summary:")
    print(df.describe().round(2).to_string())
