# ML Training Data: Source, Choice and Limitations

Draft statement for the project report (SYSTEM_ARCHITECTURE.md §8, "Training data source"). Every figure below comes from `ml_service/data/generate_dataset.py`, `ml_service/train.py` and `ml_service/model/metrics.json`. If the model is retrained, update the numbers.

## 1. Decision

The Module 5 model is trained on a **synthetic dataset** of 6,000 loan records, produced by `generate_dataset.py` (fixed seed 42). No real SACCO data was used. The choice was made because no partner SACCO had supplied anonymised historical loan and repayment data by the time of development, and a working scoring pipeline was needed to build and test Modules 5 to 8.

Each record has the eight features in SYSTEM_ARCHITECTURE.md §7 and a binary label `defaulted` (1 = more than 90 days overdue past maturity, matching `Loan::DEFAULT_THRESHOLD_DAYS`). The overall default rate in the generated data is **7.88%**.

## 2. How the data was generated

Feature distributions were chosen by the developer to look plausible for a Ugandan SACCO (for example, 72% regular savers, gamma-distributed tenure, 20% of borrowers taking high-leverage loans). The label was then **sampled from a hand-written logistic formula over those same features**, with fixed coefficients (for example +1.85 for any prior default, +0.65 per unit of loan-to-savings ratio above 2.0, plus random noise).

In other words, the "ground truth" is a rule the developer wrote, not observed borrower behaviour.

## 3. What this means for the reported metrics

| Model | ROC-AUC | Recall | Precision | Brier |
|---|---|---|---|---|
| Logistic regression (champion) | 0.899 | 0.832 | 0.325 | 0.115 |
| Random forest | 0.896 | 0.768 | 0.363 | 0.091 |
| Gradient boosting | 0.887 | 0.368 | 0.729 | 0.047 |

Test set: 1,200 records (20%), containing 95 defaults. Five-fold cross-validated ROC-AUC on the training set: 0.88 to 0.89.

These numbers must not be presented as evidence of real-world accuracy:

1. **The evaluation is circular.** The model is learning a formula the developer wrote. A high AUC shows the pipeline can recover that formula. It says nothing about how well the features predict real defaults.
2. **The champion matches the generator.** Logistic regression won, and the data was generated from a logistic formula. The three models scoring within 0.012 AUC of each other is what you would expect from this setup, and does not show that logistic regression is the better choice for real SACCO data.
3. **Precision is low.** At the chosen operating point, about two in three members the model flags as likely defaulters would in fact have repaid (precision 0.325). Class weighting trades precision for recall. This is one reason the system only recommends and a loan officer decides.
4. **The final model is refitted on all 6,000 records,** including the test set, so no held-out data remains for it. The metrics describe the model before that refit.
5. **No temporal validation.** Records are independent draws, so nothing tests whether a model trained on earlier loans holds up on later ones.

## 4. Gaps between the model and the running system

Found by reading the code. The first three were fixed in model version 1.1.0 (retrained, tests added). The default-history bug was fixed afterwards (see below); the rest remain open.

**Fixed**

- **"Default Probability" was not calibrated.** Class weighting made the raw model score average 29% against a true default rate of 7.9%. The service now returns two things: `risk_score`, the raw score, which is used only for ranking and for the rule-engine thresholds, and `default_probability`, a Platt-calibrated probability fitted on out-of-fold predictions (Brier error 0.120 to 0.049). The loan page labels the score "not a probability" and shows the calibrated figure with a note that it is calibrated on synthetic data. Calibration parameters are saved in `metrics.json`.
- **The "feature contributions" were hand-written rules.** They are now computed from the fitted model: for each feature, the applicant's score minus the score with that one feature set to the training average, in score points (positive raises risk). The values are not additive. The PHP fallback scorer now reports the exact points each of its rules added.
- **Training and live features differed.**
  - `contribution_consistency` is now 1.0 (near the volatile-saver average) when a member has too few deposits to measure it, instead of 0.0, and is kept within the training range of 0.05 to 3.0.
  - `on_time_repayment_rate` is now a per-installment measure. Loans have no stored schedule, so one is derived: equal monthly installments over the term, and an installment is on time if cumulative repayments by its due date cover the amount scheduled so far. Installments not yet due are ignored, and early payoff counts as on time. A member whose first loan has no installment due yet still gets the 1.0 clean-slate value, as in the training data.

- **Default history could be lost (resolved).** `past_default_count` used to count loans whose status was currently `defaulted`, so a defaulted loan that was later repaid became `closed` and stopped counting. Loans now carry a permanent `ever_defaulted` flag and a `first_default_date`.
  - They are set the first time a loan crosses the 90-day threshold with an unpaid balance. `Loan::refreshStatus()` does this before it can close the loan. It checks the balance as it stood on the first default day (due date + 91 days), so a loan repaid late and only refreshed afterwards is still recorded.
  - They can never be cleared or overwritten, even by a direct `update()`. `status` is unchanged: `closed` still means fully repaid.
  - `past_default_count` now counts `ever_defaulted` loans. `on_time_repayment_rate` includes every ever-defaulted loan whatever its status. A defaulted loan always misses its last installment, so a repaid-but-defaulted loan can never read as a clean record.
  - **Retrain result: no change to the test-set metrics.** The training data is synthetic and draws `past_default_count` directly, so it never had this bug, and the retrain on the same seeded data gave a byte-identical `metrics.json` (logistic regression, ROC-AUC 0.899, recall 0.832, precision 0.325, unchanged). The fix changes the live feature values, not the model. Effect on example members is below.
  - **Migration.** `2026_10_06_051211_add_default_history_to_loans_table` adds both columns and backfills existing loans: status `defaulted` is always flagged, and `overdue`, `disbursed` or `closed` loans are flagged when repayments dated before due date + 91 days did not cover `total_amount`. First default date is due date + 91 days (today, if a `defaulted` loan's due date is less than 91 days ago). Where the exact default date can't be recovered, that estimate is used. Run `php artisan migrate` on every environment. Loans repaid late whose repayment history was edited outside the app can't be recovered.

  | Example member (default repaid) | Before: defaults, risk score, calibrated probability | After |
  |---|---|---|
  | 36 months, 3 loans, 1 defaulted then repaid | 0, 9.4, 1.0% | 1, 40.0, 5.7% |
  | 12 months, 1 loan, defaulted then repaid | 0, 66.1, 14.5% | 1, 92.6, 51.0% |
  | 60 months, 5 loans, 2 defaulted then repaid | 0, 5.3, 0.5% | 2, 70.1, 16.9% |

  Examples are scored with the model in `ml_service/model/model.joblib`. Only `past_default_count` differs; the other features are the same in both columns.

**Still open**

- **No fairness or subgroup analysis.** Gender, age and location are not features, which avoids direct use, but tenure and savings balance can act as proxies. This has not been tested.
- **No drift monitoring** and no process for retraining as real loan outcomes accumulate.
- **Calibration is only as good as the data.** It makes the probabilities consistent with the synthetic default rate (7.9%). A real SACCO's rate will differ, and calibration must be redone on real outcomes.
## 5. Safeguards already in the design

- The score never decides alone. The Module 6 rule engine applies SACCO-set thresholds, and an officer makes the final call. Approving against the recommendation requires a recorded reason, and the audit log flags it as an override.
- If the ML service is down, a transparent heuristic fallback is used and labelled as such.
- Each SACCO can change its own thresholds, so the policy can be tightened without retraining.

## 6. Recommended wording for the report

> The risk model was trained and evaluated on a synthetic dataset generated from stated distributions and a logistic default process, because no real SACCO loan history was available. The reported AUC (about 0.90) therefore demonstrates that the training and scoring pipeline works end to end, not that the model predicts real Ugandan SACCO defaults at that level. Before any live use, the model must be retrained and validated on anonymised historical data from one or more partner SACCOs, with a time-based train/test split, calibration of predicted probabilities, and subgroup checks. Until then, the score is an advisory ranking, always accompanied by the rule engine's explanation and subject to officer judgement.

## 7. Path to real data

1. Agree an anonymisation and data-sharing arrangement with at least one partner SACCO (see `DATA_PROTECTION_CONSENT.md` for the legal basis and consent points).
2. Export loan outcomes with the same eight features, computed with the same definitions as `CreditRiskFeatureExtractor`, and close the open gaps in section 4 first.
3. Split by time (train on older loans, test on newer), keep the 90-day default definition, and report precision, recall, PR-AUC and calibration, not accuracy alone.
4. Recalibrate probabilities on the real default rate, and record the new `metrics.json` and dataset description in this file.
