# SACCO Credit Risk & Business Management Platform
### System Architecture & Development Guide

> This document is the single source of truth for the system's purpose, architecture, and build plan. Any developer (human or AI coding agent) should be able to read this file and understand what to build, how the pieces fit together, and in what order.

---

## 1. Project Overview

**Name (working title):** SACCO Credit Intelligence Platform

**Research topic:** Design and Development of a Machine Learning-Based Credit Risk Assessment and Business Management System for Savings and Credit Cooperative Organizations (SACCOs) in Uganda.

**Problem statement:** SACCOs commonly approve or reject loans based on manual, inconsistent judgment rather than data-driven analysis of a member's savings and repayment behavior. This leads to avoidable defaults, inconsistent lending decisions, and financial losses — particularly for SACCOs without access to formal credit bureau checks or structured risk-assessment tools.

**What the system does:**
1. Digitizes core SACCO operations (members, savings, loans, repayments).
2. Uses a Machine Learning model to score a member's loan default risk based on their own contribution/repayment history within that SACCO.
3. Applies a transparent rule engine on top of the ML score to produce an explainable lending decision (approve / require guarantor / reject).
4. Is deployed as a multi-tenant SaaS — one platform, many SACCOs, each fully data-isolated from the others.

**What the system explicitly does NOT do (current scope):**
- Does not move real money (no live Mobile Money or bank disbursement in MVP — payments are recorded, not processed).
- Does not share data across SACCOs (no cross-SACCO defaulter lookup in MVP).
- Does not use blockchain (removed from scope — centralized multi-tenant architecture instead; see §9 Decisions Log for why).
- Does not integrate with external Credit Reference Bureaus (Metropol/Compuscan) in MVP — documented as future work.

**Business model:** Multi-tenant SaaS, subscription/per-SACCO licensing. Target: Ugandan SACCOs (any tier) currently using manual/spreadsheet record-keeping and lacking any risk-scoring tool.

---

## 2. Core Design Principles

1. **Multi-tenant, data-isolated.** One codebase, one deployment, many SACCOs. Every tenant-scoped table carries a `sacco_id`. A SACCO's staff can never query or see another SACCO's data — enforce this at the query layer, not just the UI.
2. **Explainable over black-box.** The ML score is always accompanied by the rule engine's reasoning. Never surface a raw ML decision to an end user without an explanation.
3. **Append-only financial ledger.** Transaction records are never edited or deleted in place. Corrections happen via new offsetting entries. This protects auditability and is standard double-entry bookkeeping practice.
4. **Build for real deployment, not just a demo.** Every module should be something a real SACCO could actually use, even if a future module (e.g. MoMo integration) is stubbed out for now.
5. **Modular, incrementally shippable.** Each module below should be buildable and testable on its own, with clear interfaces to the others.

---

## 3. Tech Stack

| Layer | Choice | Notes |
|---|---|---|
| Backend | PHP / Laravel | Matches existing team skillset |
| Database | MySQL | Single centralized DB, tenant-isolated via `sacco_id` |
| Frontend | Blade + Bootstrap + JS (or a lightweight SPA layer if preferred) | Keep consistent with Laravel conventions |
| ML Service | Python (scikit-learn / XGBoost) | Runs as a separate service, exposed via internal API — see §5.3 |
| Charts/Reporting | Chart.js | |
| Auth | Laravel built-in auth + role-based access control (Admin, Loan Officer, Member-facing views if needed) | |
| Hosting | VPS / cloud (TBD) | |

**Why a separate Python ML service instead of a PHP ML library:** PHP's ML ecosystem is weak. Laravel calls an internal Python microservice (FastAPI/Flask) over a private API for scoring. This is a deliberate architectural decision — document it in your report as a justified design choice, not an inconsistency.

---

## 4. High-Level Architecture

```
                          ┌─────────────────────────┐
                          │   Laravel Web App        │
                          │  (multi-tenant, sacco_id) │
                          └───────────┬───────────────┘
                                      │
                 ┌────────────────────┼────────────────────┐
                 │                    │                    │
        ┌────────▼────────┐ ┌─────────▼─────────┐ ┌────────▼────────┐
        │   MySQL (DB)     │ │  Rule Engine       │ │  ML Scoring API  │
        │  members, loans, │ │  (PHP, in Laravel) │ │  (Python service)│
        │  savings, txns   │ │  score -> decision  │ │  features -> score│
        └──────────────────┘ └────────────────────┘ └──────────────────┘
```

**Request flow for a loan application (the core use case):**
1. Loan officer submits a loan application in the web app.
2. Laravel gathers the member's feature set (contribution consistency, repayment history, savings pattern, loan-to-savings ratio) from MySQL.
3. Laravel calls the ML Scoring API with these features → gets back a risk score (e.g. 0–100 or a probability).
4. The Rule Engine (in Laravel) applies SACCO policy to the score (e.g. `score > 75 → reject`, `50–75 → require guarantor`, `< 50 → approve`).
5. Officer sees: risk score + explanation + recommended decision. Officer makes the final call (system recommends, doesn't force).
6. Decision and reasoning are logged to the append-only transaction/audit log.

---

## 5. Modules

Build in this order. Each module should be functional and demoable before moving to the next.

### Module 1 — Foundation & Multi-Tenancy (COMPLETED)
- Laravel project setup, database migrations.
- `saccos` table, `users` table (with `sacco_id`, role), auth + login.
- Global query scoping so every model automatically filters by the logged-in user's `sacco_id`.
- Super-admin panel to onboard new SACCOs (you, as the platform operator).
- **Done when:** two test SACCOs can be created, each with its own admin user, and logging in as one never surfaces the other's data. *(Completed & Tested)*

### Module 2 — Member Management (COMPLETED)
- `members` table: bio-data, national ID (cryptographically hashed SHA-256 where sensitive, masked hint for inspection, per Uganda Data Protection & Privacy Act 2019), join date, status, tenure calculation.
- CRUD for members, search/filter (name, phone, NIN, member no), status deactivation/activation.
- **Done when:** a SACCO admin can add, edit, view, and deactivate members, scoped to their own SACCO only. *(Completed & Tested)*

### Module 3 — Savings & Contributions (COMPLETED)
- `savings` table: append-only ledger (`sacco_id`, `member_id`, `amount`, `transaction_date`, `recorded_by`, `type`=deposit/withdrawal, `transaction_reference`, `payment_method`, `payment_reference`, `notes`).
- Running balance computed directly from ledger: `SUM(deposits) − SUM(withdrawals)`, never stored/mutated directly.
- Append-only immutability enforced (in-place updates and deletions prohibited on financial transactions).
- Payment channels: Cash, MTN MoMo, Airtel Money, Bank Transfer, other.
- Member passbook & printable official transaction receipts.
- **Done when:** contributions can be recorded and a member's savings history/balance displays correctly. *(Completed & Tested)*

### Module 4 — Loans & Repayments (COMPLETED)
- `loans` table: member_id, principal, interest terms, status (pending/approved/disbursed/defaulted/closed), dates.
- `repayments` table: append-only ledger against a loan (`amount`, `payment_method`, `payment_reference`, immutable).
- Loan balance computed dynamically: `total_payable − sum(repayments)`.
- Default detection policy: grace period <= 90 days (`overdue`), > 90 days past maturity (`defaulted`), background scan command (`php artisan loans:check-overdue`).
- **Done when:** a loan can be applied for, approved, disbursed (recorded), repaid in installments, and its status updates correctly including default flagging. *(Completed & Tested)*

### Module 5 — ML Risk Scoring Engine (COMPLETED)
- Python service (FastAPI/Flask), exposes `POST /score` accepting a member's feature vector, returns a risk score/probability.
- Feature engineering from Modules 3 & 4 data: contribution consistency (frequency/regularity), repayment history (on-time rate, past defaults), savings-to-loan ratio, tenure.
- Model: start with logistic regression (interpretable baseline), compare against random forest / gradient boosting; pick based on evaluation metrics (precision, recall, AUC — not just accuracy, since default is a minority class).
- Training data: **this needs a real or realistic dataset early** — flag this as a priority task, not an afterthought (see §8).
- **Done when:** given a member's history, the service returns a score and the Laravel app can call it successfully. *(Completed & Tested)*

### Module 6 — Rule Engine & Explainable Decisions (COMPLETED)
- PHP logic layer: takes the ML score + configurable SACCO policy thresholds → produces a decision + human-readable explanation.
- SACCO admin should be able to configure their own thresholds (not hardcoded) — this is both good design and a strong "configurability" point for your defense.
- **Done when:** applying for a loan produces a score, a decision, and a plain-English reason, and thresholds are adjustable per SACCO. *(Completed & Tested)*
- Implemented as `LendingPolicy` (one row per SACCO, defaults 50 / 75) and `LendingDecisionEngine`. The strictest triggered rule wins. Extra rules: loan-to-savings cap, minimum tenure, previous default. The decision and reason are stored on `loans.decision` / `decision_reason` at application time. Approving against the recommendation requires a recorded reason.

### Module 7 — Reporting & Dashboard (COMPLETED)
- Dashboard: today's savings, outstanding loans, at-risk members, portfolio health.
- Charts (Chart.js): sales/contribution trends, default rate over time, top-risk members.
- Exportable reports (PDF/CSV) for SACCO management.
- **Done when:** a SACCO admin has a working dashboard giving a real operational snapshot. *(Completed & Tested)*
- Implemented in `PortfolioReportService`. Portfolio at risk = balance of overdue and defaulted loans ÷ total outstanding balance. The default-rate chart is cohort-based (loans disbursed in a month that have since defaulted). Reports are admin-only: CSV exports of the savings ledger and loan portfolio, plus a printable summary (PDF via the browser's print dialog, so no PDF library was added).

### Module 8 — Audit Log & Data Integrity (COMPLETED)
- Every financial write action (savings, loan, repayment, decision override) logged immutably: who, what, when.
- Read-only audit trail view for admins.
- **Done when:** every money-related action in the system is traceable after the fact. *(Completed & Tested)*
- Implemented as `audit_logs` (append-only, one SHA-256 hash chain per SACCO) written by `AuditTrail` from model events. Logged actions: `savings.recorded`, `repayment.recorded`, `loan.applied`, `loan.approved`, `loan.rejected`, `loan.disbursed`, `policy.updated`. Approvals and rejections that go against the rule engine carry `override: true`. Admins get a read-only audit page plus a "Verify Integrity" action (also `php artisan audit:verify`) that detects edited or removed log entries and edited or deleted ledger rows. Limitation: entries truncated from the very end of a chain can't be detected without an external anchor.

### Module 10 — Member Portal (COMPLETED, read-only)
- A separate member-facing login so a member can see their own records. View-only: members cannot edit records or take any financial action.
- Separate `member` auth guard (`members` provider) and `/portal/*` routes behind the `member_portal` middleware. Staff and member sessions are independent.
- **Credentials are issued by staff.** A SACCO admin switches portal access on from the member profile, which generates a globally unique login name (member numbers repeat across SACCOs) and a temporary password shown once. The member must choose their own password at first sign-in. No SMS or email, so no new dependencies. Admins can reset the password or switch access off. Login is throttled, failures share one message, and access stops at once if the member or SACCO is deactivated.
- Members see: their details (national ID masked), savings balance and full history, loans with status, balance and repayment history, and their consent record.
- Members can send a **consent change request** (stop scoring, allow scoring, or stop the SACCO using their information). It changes nothing by itself. Staff see pending requests on the member profile, make the change with the existing consent form, then mark the request processed, or dismiss it with a reason the member can read. Requests and their handling are written to the audit trail.
- Members are not shown the risk score, the rule-engine decision or reason, guarantor details or internal notes.
- **Done when:** a member can sign in, see only their own savings, loans, repayments and consent, and ask for a consent change, with no way to write anything else. *(Completed & Tested: `MemberPortalTest`)*
- Limits: no self-registration, forgotten-password flow, or SMS/email. A member who forgets their password asks the SACCO for a reset. Withdrawing consent to data processing is recorded as a request only; there is no automated exit or anonymisation (see `DATA_PROTECTION_CONSENT.md` section 6).

### Savings withdrawal requests (member portal, manual payout)
- Members ask to withdraw savings from `/portal/savings/withdraw`; staff review them at `/withdrawals` and pay the member **by hand**. No payout API is used and nothing is automated. Members cannot write to the savings ledger: the single place a withdrawal ledger row is created from a request is `WithdrawalRequestService::recordPayout()`, which calls `Saving::recordLocked()`.
- **Tables.** `withdrawal_policies` (one row per SACCO, unique `sacco_id`, `BelongsToTenant`; a SACCO with no row uses the model defaults) and `withdrawal_requests` (`sacco_id`, `member_id`, `reference` WDR-YYYY-NNNNN from `DocumentSequence` name `withdrawal_request`, `amount`, `early`, `fee_amount`, `net_amount`, `destination_phone` +256, `status` pending|approved|rejected|paid|cancelled|expired, `available_from`, `member_notes`, `decided_by/at`, `decision_reason`, `paid_by/at`, `payout_method`, `payout_reference`, `saving_id` UNIQUE, `cancelled_at`, `expired_at`). Requests are never deleted (the model throws). `members` gained `portal_password_changed_at` and `phone_changed_at` (not mass assignable; the phone stamp is set in `Member::booted()` only when the normalised number really changes, the password stamp in `issuePortalCredentials()` / `changePortalPassword()`).
- **Settings** (SACCO admin only, `/settings/withdrawal-rules`, audit `withdrawal_policy.updated` with old/new values; defaults are placeholders, not legal rules): `notice_days` 7; `loan_cover_mode` `block_while_loan_active` or `keep_ratio_cover` (savings after the withdrawal must stay >= outstanding loan principal / the lending policy's `max_loan_to_savings_ratio`; a null ratio behaves like block mode); `early_withdrawal_fee_percent` 0 to 20 (early withdrawal is offered only when > 0; fee is taken from the payout, the ledger records the full amount); `daily_limit_per_member` 1,000,000 (null = none); `admin_approval_threshold` 500,000 (above it only a sacco_admin may approve; null = admin always); `security_hold_days` 3 (0 = off); `request_expiry_days` 30; `payer_must_differ_from_approver` above_threshold. "Outstanding" loans are disbursed, overdue and defaulted.
- **Request flow.** The member enters an amount (and the early choice if offered) and re-enters their portal password. Everything else (member, SACCO, destination phone = registered phone normalised, fee, dates) comes from the server. Checks at submit, under a lock on the member row: active member, valid phone, one open (pending/approved) request, security hold, early allowed, amount <= balance minus own open requests, minimum UGX 1,000 (or the whole balance), loan cover, daily limit (open requests created today + ledger withdrawals today). Wrong passwords are limited to 5 per 15 minutes per member; submit/cancel are limited by `portal-withdrawals` (6 per minute per member).
- **Staff.** Approve only on/after `available_from`; above the threshold only a sacco_admin. Reject needs a reason (the member sees it). **Record payout** on an approved request runs in one transaction: lock the request row, confirm approved and unpaid, re-check notice date, balance, loan cover and daily limit (inside `Saving::recordLocked()` under the member lock), write the one ledger row, link `saving_id`, mark paid. A repeated click finds it paid and fails; `saving_id` UNIQUE is the last safety net. Staff cannot edit amount, member or destination.
- **Counter withdrawals** (`TenantSavingsController`) now also obey the loan cover rule and the daily limit (counted per transaction date) inside `Saving::recordLocked()`. Notice and the security hold are for portal requests only.
- **Expiry.** `php artisan withdrawals:expire` (scheduled daily at 12:10 UTC, inside the hours the VM is on) expires open requests when today is after `available_from + request_expiry_days`; log in `storage/logs/withdrawal-expiry.log`.
- **Audit** (`withdrawal.requested|approved|rejected|paid|cancelled|expired`, `withdrawal_policy.updated`) with plain labels in `AuditLog`. Portal withdrawal pages send `Cache-Control: no-store, private`; the service worker never caches HTML or POSTs.
- **Separation of duties.** Setting `payer_must_differ_from_approver` (`never` | `above_threshold` default | `always`) is enforced on "Record payout" against the approver stored on the request (`decided_by`). `above_threshold` applies to requests above the administrator approval amount. A SACCO with one staff member must choose `never`, otherwise it cannot pay large requests. The change is part of the `withdrawal_policy.updated` audit entry.
- **Lock order.** Always request row -> member row -> (loan row). Payout locks the request, then the member inside `Saving::recordLocked()`. Loan disbursement (`Loan::disburse()`) locks the member row first, then re-reads the loan row under lock and re-checks that it is still approved, so a disbursement and a payout for the same member queue behind each other and no path takes the member and loan locks in the opposite order.
- **Scheduled jobs and the VM's off hours.** The VM is off 19:00-06:00 UTC. `loans:check-overdue` (12:00) and `withdrawals:expire` (12:10) are inside that window's complement; `payments:reconcile` runs every 15 minutes and simply does not run while the VM is off. A test fails if a command is pinned to a fixed hour in the off window.
- **Demo data.** `php artisan db:seed` creates withdrawal rules with `security_hold_days = 0` for the two demo SACCOs when they have no saved rules (it never overwrites saved rules). For a SACCO that already has a row, set the hold to 0 on the Withdrawal Rules screen. A member's first portal password choice counts as a password change, so the hold matters on day one in a real SACCO.
- **Concurrency check on MySQL (manual, not part of the suite).** The automated tests use SQLite, which ignores row locks. `scripts/mysql-concurrency-check.php` starts real parallel processes against MySQL and prints whether exactly one ledger row was created when (A) two payouts hit the same approved request and (B) two withdrawals race for one balance. Run it only against an empty scratch database, never production data:
  1. `CREATE DATABASE sacco_concurrency_test;` on a MySQL server (any name containing `test`, `scratch` or `concurrency`, and different from `DB_DATABASE`). The `pdo_mysql` PHP extension is required.
  2. From the project root: `CONCURRENCY_DB=sacco_concurrency_test php scripts/mysql-concurrency-check.php --rounds=5` (PowerShell: `$env:CONCURRENCY_DB='sacco_concurrency_test'; php scripts/mysql-concurrency-check.php --rounds=5`). Host and credentials come from the usual `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`.
  3. The script runs the migrations in that database, creates its own sample SACCOs (license `CONC-...`), and refuses to run if the database holds anything else. Exit code 0 means every round produced exactly one ledger row; 1 means a failure.
- Tests: `WithdrawalPolicyTest`, `WithdrawalRequestTest`.

### Module 9 (Future Work — document, don't build in MVP)
- MTN MoMo Collections API integration (webhook-based auto-reconciliation).
- Bank statement API integration.
- External CRB (Metropol/Compuscan) lookup as an optional additional feature to the ML model.
- Multi-SACCO opt-in default sharing (centralized, consent-based — not blockchain).

---

## 6. Database Schema (Core Tables — MVP)

```sql
saccos (id, name, license_number, tier, created_at)

users (id, sacco_id, name, email, password, role, created_at)

members (id, sacco_id, full_name, national_id_hash, phone, join_date, status)

savings (id, sacco_id, member_id, amount, type, payment_method, recorded_by, created_at)
-- append-only; balance = SUM(deposits) - SUM(withdrawals)

loans (id, sacco_id, member_id, principal, interest_rate, status, applied_at, approved_at,
       disbursed_at, due_date, risk_score, decision, decision_reason)

repayments (id, sacco_id, loan_id, amount, payment_method, recorded_by, created_at)
-- append-only

audit_log (id, sacco_id, user_id, action, entity_type, entity_id, details_json, created_at)

staff_invitations (id, user_id, sacco_id, token_hash, expires_at, used_at, created_by, created_at)
-- one-time staff invitation; only the SHA-256 hash of the emailed token is stored

withdrawal_policies (id, sacco_id UNIQUE, notice_days, loan_cover_mode, early_withdrawal_fee_percent,
       daily_limit_per_member, admin_approval_threshold, security_hold_days, request_expiry_days)

withdrawal_requests (id, sacco_id, member_id, reference, amount, early, fee_amount, net_amount,
       destination_phone, status, available_from, decided_by, decided_at, paid_by, paid_at,
       payout_method, payout_reference, saving_id UNIQUE)
-- never deleted; a ledger row exists only after staff record the manual payout
```

*(Full column-level schema with types/constraints to be finalized during Module 1 implementation.)*

---

## 7. ML Feature Set (Initial)

| Feature | Description |
|---|---|
| `contribution_frequency` | How regularly the member deposits (e.g. deposits per month, variance in timing) |
| `contribution_consistency` | Coefficient of variation of deposit amounts |
| `savings_balance` | Current savings balance |
| `tenure_months` | Months since joining the SACCO |
| `past_loans_count` | Number of previous loans |
| `past_default_count` | Number of previous defaults within this SACCO |
| `on_time_repayment_rate` | % of past repayments made by due date |
| `loan_to_savings_ratio` | Requested loan amount ÷ current savings balance |

Label: `defaulted` (binary) — a loan that went unpaid past the SACCO's defined default threshold.

---

## 8. Open Items / Things to Resolve Early

- [x] **Training data source.** Synthetic dataset, limitations stated in `ML_DATA_LIMITATIONS.md`. Original question: decide: real anonymized historical data from a partner SACCO, or a carefully-justified synthetic dataset with realistic distributions. State the choice and its limitations explicitly in the report — don't leave this vague.
- [x] **Default definition.** 90+ days past maturity (`Loan::DEFAULT_THRESHOLD_DAYS`), also the ML label. Agree on a precise, consistent rule for when a loan is "defaulted" (e.g. 90+ days overdue) — needed both for the database and the ML label.
- [x] **Rule engine thresholds.** Defaults 50 / 75, editable per SACCO (Module 6). Default starting thresholds before SACCOs customize them.
- [ ] **Data protection compliance.** Draft in `DATA_PROTECTION_CONSENT.md`, pending legal review and the gaps listed there. Consent language for members, per Uganda's Data Protection and Privacy Act (2019), before any real deployment.

---

## 9. Decisions Log (why things are the way they are)

| Decision | Reasoning |
|---|---|
| Centralized multi-tenant DB, not blockchain | Blockchain solves cross-SACCO trust, which is out of current scope. A trusted centralized SaaS operator model is standard, cheaper, faster to build, and matches how SACCOs already trust software vendors. |
| No cross-SACCO data sharing in MVP | Requires legal/consent groundwork and validated demand from real SACCO customers before building — not assumed. |
| ML term used instead of "AI" | More precise — the system uses supervised classification specifically, not broader AI techniques. |
| Manual payment entry, not live MoMo integration, in MVP | Matches how most SACCOs currently operate; live integration requires MTN business onboarding, adding real time/cost — documented as future work. |
| Separate Python ML service instead of PHP ML library | PHP's ML ecosystem is immature; Python (scikit-learn/XGBoost) is the standard, well-supported choice for this kind of model. |
| Append-only transaction ledger | Standard double-entry bookkeeping practice; protects auditability and answers "why does this balance say X" disputes. |
| Member portal is view-only with staff-issued credentials (Module 10) | Gives members access to their own data (a data-subject right under the DPPA) without new attack surface or an SMS/email provider. Members cannot write to financial records. Consent changes are requests that staff action, so the audited consent record is only ever written by staff. |
| Members do not see their risk score or decision in the portal | Showing a score without a person to explain it can mislead. Members can ask the SACCO for an explanation instead, which keeps the officer's decision as the decision of record. Revisit with the SACCO and legal review. |
| Staff accounts are created by administrators and activated by an emailed one-time link | Closed system, no self-registration. The link (`/staff/invite/{token}`, 48 h, single use, hash stored) lets the person set their own password and confirms their address. "Forgot password" (`/forgot-password`, `/reset-password/{token}`, Laravel's password broker, 60 min) answers identically for unknown addresses and works only for active staff of an active SACCO. Mail is configured only through `MAIL_*` in `.env`; sending is synchronous inside try/catch (queue with `MAIL_QUEUE=true`), so a mail failure never blocks sign-in or rolls back an account. Fallback: an administrator can set a temporary password. See `docs/EMAIL_SETUP.md`. |
| Members are scored only with a recorded consent that allows it | One strict rule with no mode switch. Sample data satisfies it the same way real data does. |
| Savings withdrawals from the portal are requests paid out by hand | Keeps money-moving writes with staff, matches how SACCOs pay today, and the ledger row is created only at payout under a lock with every rule re-checked. Rules are per-SACCO settings; defaults are placeholders. |

---

## 10. Brand & UI Design System

The visual identity is derived from the Fat Tech Computer World logo (black + green-to-teal gradient + cool grey swoosh, bold condensed geometric type). Every screen in this system should be built from these tokens — do not introduce new colors, shadows, or card styles ad hoc per module.

### Color tokens
| Token | Hex | Use |
|---|---|---|
| `color-black` | `#0F172A` | Primary text, headers, high-emphasis UI, and large dark surfaces such as the sidebar. A very dark navy: the logo's pure black works as a small mark but is too heavy as a large background. Change it here and in the three `:root` blocks (`layouts/app`, `layouts/portal`, `auth/login`) together. |
| `color-green` | `#1FAE5C` → `#2FD98A` | Primary brand accent (gradient), buttons, active states — used deliberately, not washed across backgrounds |
| `color-teal` | `#1B7A6E` | Secondary accent — secondary actions, second data series in charts |
| `color-bg` | `#F5F6F7` | Page background — cool neutral, not cream |
| `color-slate` | `#4A5058` | Borders, secondary/muted text |
| `color-risk-low` | `#2E9E5B` | Reserve for genuine "low risk" status — may coincide with brand green, that's fine |
| `color-risk-medium` | `#D98F2B` | Amber — status color, independent of brand palette |
| `color-risk-high` | `#C4432E` | Red — status color, independent of brand palette |

**Rule:** brand color (green/teal) signals identity and primary actions. Risk-status color signals data meaning. Never let brand color override what a risk indicator needs to honestly communicate.

### Typography
- Headings: a bold, condensed geometric sans (something in the spirit of the logo's lettering — e.g. a condensed grotesk) used deliberately for section headers and key numbers (balances, risk scores), not for body text.
- Body/UI text: a clean, highly legible sans at normal width — loan officers and treasurers need to read numbers fast, not admire display type.
- Two typefaces max. Clear size/weight scale, not ad hoc sizing per screen.

### Layout principles
- Structural, slightly squared UI (sharp-ish corners, confident dividers) rather than bouncy rounded SaaS cards — matches the geometric, industrial character of the logo.
- No identical rounded cards with the same soft grey shadow on everything regardless of content — hierarchy should be visible in the layout, not just in a repeated card shell.
- No tracked-out ALL-CAPS labels, no middle-dot separators, no arrows tacked onto every button, no unnecessary "eyebrow" labels above headings — these are generic AI-generated UI tells, avoid them.
- Motion: sparing and purposeful — reserve for moments that show real state change (a decision confirming, a value updating), not decorative hover/fade effects on every element.
- Design the core screen first — the loan officer's risk-assessment/decision view — and derive the rest of the system's visual language from it, rather than designing every screen independently.

### Wording on screen
- Screens speak to the person using them (SACCO admin, loan officer, member, platform operator) in plain language. Never show build progress, module numbers, file names, database field or table names, or internal terms such as tenant, rule engine, ML, microservice, append-only or hash. Say lending policy, credit risk score, savings transaction, repayment history.
- Build and module status belongs in this file and the project notes, never in the running application. Do not leave developer notes in HTML comments either, because they are sent to the browser; use Blade comments.
- Audit log entries are shown with readable action names and details (AuditLog::actionLabel() and displayDetails()), not the stored codes.
- NoInternalLanguageTest renders each screen for each kind of user and fails on this kind of language.

### Responsive behaviour (phone, tablet, desktop)
Every screen must work at about 375px (phone), 768px (tablet) and desktop widths, using only the tokens above (no extra colours, shadows or patterns). The rules live in `layouts/app.blade.php` (staff and super admin) and `layouts/portal.blade.php` (member portal):
- **Sidebar:** fixed at 992px and wider. Below that it is an off-canvas menu opened from a hamburger in the topbar, closed by the backdrop, the close button, Escape or tapping a link.
- **Topbar:** the title and primary actions wrap; on phones the actions sit on their own row, two per row, so they are never cut off.
- **Tables:** list screens (members, loans, savings, repayments, staff, SACCOs) carry `table-stack` and a `data-label` on every cell, so on phones each row becomes a labelled card. Dense tables (audit log, statements, dashboard lists, reports) stay tables and scroll sideways inside their own card, never the page. New list tables should use `table-stack` with `data-label`; keep the `table-responsive` wrapper either way.
- **Forms and controls:** up to 991px, inputs and buttons are at least 44px high with 16px text (stops iOS zooming on focus). Fields stack in one column below 768px.
- **Stat tiles and charts:** tiles go two per row on phones and equal-height on tablets. Charts keep `maintainAspectRatio: false` in a fixed-height container and use compact axis numbers on phones.
- **Member portal is mobile-first:** the base CSS is the phone layout, with a bottom tab bar (top links from 768px), card-style `txn-list` lists on phones and tables from 768px, and whole-row tap targets for choices.

### Design brief prompt (paste into a coding session when building or restyling any UI)

> Build this screen using the Fat Tech Computer World brand system defined in §10 of this file: black (#0F172A), green-to-teal gradient (#1FAE5C → #2FD98A, #1B7A6E) as the primary accent used deliberately (not washed across backgrounds), a cool neutral background (#F5F6F7), slate grey (#4A5058) for secondary text/borders. Risk-status colors (#2E9E5B low / #D98F2B medium / #C4432E high) are independent of brand color and must stay honest to their meaning. Use a bold condensed geometric typeface for headings/key numbers and a clean legible sans for body/UI text — two typefaces max. Favor a structural, slightly squared layout over rounded SaaS-card defaults; avoid identical repeated card shells, tracked-out uppercase labels, middle-dot separators, arrow-suffixed buttons, and decorative hover/fade motion on every element. This is financial software used daily by SACCO staff — prioritize clarity and trust over visual flourish. Ground every design decision in this specific brand and this specific audience, not generic dashboard conventions.

---

## 11. How to Use This File (for an AI coding agent or new developer)

- Read this file in full before writing any code.
- Build strictly module by module, in the order listed in §5. Do not start Module *n+1* before Module *n* is functional and testable.
- Any architectural decision not covered here should be added to §9 (Decisions Log) once made, so this file stays the single source of truth.
- Update this file as modules are completed or scope changes — treat it as a living document, not a one-time spec.
