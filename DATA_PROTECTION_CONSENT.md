# Data Protection and Member Consent (Draft)

Draft for SYSTEM_ARCHITECTURE.md §8, "Data protection compliance". It is based on Uganda's Data Protection and Privacy Act, 2019 (DPPA), which is regulated by the Personal Data Protection Office (PDPO) under NITA-U.

**This is a working draft, not legal advice.** Before any real deployment, have it reviewed by a lawyer or the PDPO, and confirm section references against the Act's current text (the Act is published as Chapter 97 in the 2023 revised laws, so numbering may differ from the 2019 original). Items marked **[confirm]** are points I could not verify.

## 1. Roles

| Party | Role under the Act | Why |
|---|---|---|
| Each SACCO | Data controller | It decides why and how its members' data is collected and used. |
| Platform operator (the SaaS provider) | Data processor | It stores and processes the data only on behalf of each SACCO. |

Consequence: the platform operator should sign a **data processing agreement** with each SACCO covering purpose, security, sub-processors, breach notification, return or deletion of data on exit, and no use of one SACCO's data for another. Whether the controller or processor must register with the PDPO is **[confirm]**.

## 2. What personal data the system holds

| Data | Where | Notes |
|---|---|---|
| Name, phone, email, address, date of birth, gender, occupation, income | `members` | Gender and date of birth are not used by the scoring model. |
| National ID number (NIN) | `members.national_id_hash`, `national_id_masked` | Only a hash and a masked hint are stored, not the number. |
| Next of kin name, phone, relationship | `members` | Third-party data: the member should tell their next of kin. |
| Guarantor name, phone, relationship | `loans` | Third-party data: the guarantor's consent is needed too. |
| Savings, loans, repayments | ledger tables | Financial data, append-only. |
| Derived credit score and decision | `loans.risk_score`, `decision`, `decision_reason` | Output of automated processing (see section 5). |
| Staff names and actions | `users`, `audit_logs` | Staff are data subjects too. |

## 3. Member-facing consent notice (plain-language text)

Suggested for printing on the membership form and reading aloud. Translate into Luganda and other local languages used by the SACCO, and keep the English and translated versions identical in meaning.

> **How [SACCO NAME] uses your information**
>
> [SACCO NAME] collects your name, contact details, National ID number, savings, loan and repayment records so that we can manage your membership, record your savings, and decide on and manage loans.
>
> **Credit scoring.** When you apply for a loan, we use a computer program to calculate a risk score from your own savings and repayment history with this SACCO. The score is a guide only. A loan officer makes the final decision, and you can ask us to explain it.
>
> **Who sees your information.** Only authorised staff of [SACCO NAME]. Our software provider, [PLATFORM OPERATOR], stores it on our behalf and may not use it for any other purpose. We do not share your information with other SACCOs, credit bureaus or anyone else, except where the law requires it.
>
> **Your National ID.** We do not keep your ID number itself. We keep a scrambled version used to avoid duplicate registrations, and the last few characters so staff can recognise it.
>
> **Your rights.** You can ask to see your information, correct it, or ask that it be removed where we are not required to keep it. You can ask that a loan decision not be made by computer alone, and you can ask us to reconsider a decision. Contact [CONTACT NAME, PHONE, EMAIL]. You may also complain to the Personal Data Protection Office.
>
> **How long we keep it.** [RETENTION PERIOD, see section 6].
>
> Giving consent is your choice. If you do not consent to credit scoring, you can still apply for a loan, which will be decided by a loan officer without the score.

## 4. Consent record (form wording)

> I have read, or had read to me, the notice above in a language I understand.
>
> ☐ I agree that [SACCO NAME] may collect and use my personal information to manage my membership, savings and loans.
> ☐ I agree that my savings and repayment history with this SACCO may be used to calculate a credit risk score when I apply for a loan.
> ☐ I confirm I have told my next of kin and guarantor (if any) that I gave their details.
>
> Name: ____________ Member No.: ________ Signature or thumbprint: ____________ Date: ________
> Witness (staff): ____________

The two main agreements are separate boxes so that consent is **specific and freely given**, as the Act defines consent. Refusing credit scoring must not block the member from borrowing.

## 5. Automated decisions (the DPPA's key point for this system)

The Act gives a data subject the right to require, by written notice, that a decision which significantly affects them is **not based solely on automated processing**, and to have such a decision reconsidered (the source I checked gives a 21-day window; **[confirm]**).

How the system already supports this:

- The ML score and the rule-engine result are a **recommendation**. A named officer approves or rejects every loan, and approving against the recommendation needs a written reason.
- The reason text shows which rules triggered, and the audit log records who decided and whether they overrode the recommendation.
- A member who declines credit-scoring consent, or who has no consent record, is excluded from scoring automatically (see section 8, item 1). The system still has no per-application switch for a member who consented but later objects to one decision. Until that is built, record the withdrawal on the member's profile (which stops scoring for new applications) and decide the application manually.

## 6. Retention and deletion (decisions needed)

The system does not yet delete or anonymise anything. The SACCO and operator must decide:

- how long member and loan records are kept after a member leaves or a loan closes (financial-record laws may require several years: **[confirm]** the period);
- what happens to a member's personal details when they exit but their ledger entries must remain. The ledger and audit log are append-only by design, so removal should mean anonymising the member record (name, phone, etc.) while keeping the financial entries.

## 7. Safeguards already in the system

- **Tenant isolation:** every query is filtered by `sacco_id`, and tests confirm that one SACCO cannot see another's data.
- **No NIN storage:** only a hash and mask.
- **Role-based access:** loan officers and SACCO admins have different permissions. Reports, audit log and staff management are admin-only.
- **Immutable audit trail:** financial actions are logged in a tamper-evident chain that admins can verify.
- **Minimal data for scoring:** the model uses only the member's own savings, loan and repayment history within that SACCO. It uses no gender, age, location or external data.

## 8. Gaps to close before real deployment

1. **Consent capture (built; legacy members and signed forms still open).** Registration now has a consent section with three answers matching section 4: data processing (required, or the member can't be registered), credit scoring (must be answered, yes or no), and next of kin informed. The app stores the notice version (`Member::CONSENT_NOTICE_VERSION`, bump it when section 3's wording changes), date, capturing staff member and the choices on `members`. Every capture or change also writes a `member.consent_recorded` entry to the audit log with the previous scoring choice, so withdrawals are kept in the tamper-evident chain. Staff can record or change consent from the member's profile page. A member who declines scoring still borrows: no ML call is made, no score is stored, and the loan page tells the officer to decide manually. There is one scoring rule, with no mode or environment switch: **a member is scored only if a consent record exists and scoring was agreed.** Declined or no record means no ML call and no score, and the loan goes to an officer to decide manually. Scoring consent depends on data-processing consent: the form disables the scoring answer until data processing is ticked, and the server rejects "scoring yes" without it. Saying no to data processing means the member cannot be registered at all, so "no to both" is never stored. Sample data from the seeder carries the same consent record a real registration produces (both agreed, timestamped, captured by the SACCO admin). Remaining:
   - Members created before consent capture existed have no consent record (`consent_version` is null) and are not scored until staff capture it on the member's profile.
   - The app records that staff captured the consent. It does not store the signed form or thumbprint, so keep the paper form.
   - Consent is changed only by an officer or admin of the SACCO. A member can now see their consent record in the member portal and send a change request (stop scoring, allow scoring, or stop the SACCO using their information), but the request does nothing until staff act on it. Requests and their handling are in the audit trail. A request to stop data processing is only recorded; the exit and anonymisation process is still to be decided (section 6).
2. **The notice overstates the NIN protection.** The registration screen calls the SHA-256 hash "irreversible". A National ID number has a small, structured set of possible values, and an unsalted hash can be reversed by trying them all. Either use a keyed hash (for example HMAC with a server secret, which still allows duplicate and search matching) and soften the wording, or word it as "protected by a one-way hash".
3. **No data-subject request handling,** such as exporting or correcting a member's data.
4. **No retention or anonymisation job** (section 6).
5. **No breach-response procedure.** Define who is told, and how quickly, if data is exposed.
6. **No data processing agreement or PDPO registration** yet.
7. **The training data is synthetic** (see `ML_DATA_LIMITATIONS.md`). Using real SACCO data for model training later needs its own legal basis and anonymisation.

## 9. Sources

- [Uganda Data Protection and Privacy Act, Chapter 97 (ULII)](https://ulii.org/en/akn/ug/act/2019/9/eng@2023-12-31/source)
- [Securiti: A Complete Guide on Uganda's DPPA](https://securiti.ai/uganda-data-protection-and-privacy-act/)
