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
| Members are scored only with a recorded consent that allows it | One strict rule with no mode switch. Sample data satisfies it the same way real data does. |

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
