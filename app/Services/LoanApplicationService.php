<?php

namespace App\Services;

use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The single place a loan application is priced, scored, given a recommendation and saved.
 * Used by both the staff loan form and the member portal request.
 */
class LoanApplicationService
{
    public function __construct(
        private CreditRiskScoringService $scoringService,
        private LendingDecisionEngine $decisionEngine,
    ) {}

    /**
     * Create a pending loan application.
     *
     * @param  array{principal: float|int|string, interest_rate: float|int|string, interest_calculation_type: string, term_months: int|string, applied_at: string, purpose?: ?string, guarantor_name?: ?string, guarantor_phone?: ?string, guarantor_relationship?: ?string, notes?: ?string}  $data
     */
    public function create(Member $member, array $data, ?int $createdBy, string $requestedVia = Loan::REQUESTED_VIA_STAFF): Loan
    {
        $saccoId = (int) $member->sacco_id;

        $principal = (float) $data['principal'];
        $rate = (float) $data['interest_rate'];
        $termMonths = (int) $data['term_months'];
        $calcType = $data['interest_calculation_type'];

        $interest = Loan::calculateInterest($principal, $rate, $termMonths, $calcType);
        $totalAmount = round($principal + $interest, 2);

        $riskScore = null;
        $ruling = ['decision' => null, 'reason' => null];
        if (! $member->canBeScored()) {
            // Not being scored never blocks borrowing: the loan goes to an officer without a score
            $ruling['reason'] = $member->hasDeclinedScoring()
                ? 'Member declined credit scoring. No risk score was calculated; decide on the application manually.'
                : 'No consent record for this member. No risk score was calculated; record consent on the member profile, or decide on the application manually.';
        } else {
            $riskAssessment = $this->scoringService->scoreMember($member, $principal);
            $riskScore = $riskAssessment['risk_score'] ?? null;
            $ruling = $this->decisionEngine->decide(
                $riskAssessment,
                LendingPolicy::resolveFor($saccoId),
                ! empty($data['guarantor_name'])
            );
        }

        return DB::transaction(function () use ($member, $data, $saccoId, $principal, $rate, $termMonths, $calcType, $interest, $totalAmount, $riskScore, $ruling, $createdBy, $requestedVia) {
            $loan = Loan::create([
                'sacco_id' => $saccoId,
                'member_id' => $member->id,
                'loan_reference' => Loan::generateReference($saccoId),
                'principal' => $principal,
                'interest_rate' => $rate,
                'interest_calculation_type' => $calcType,
                'term_months' => $termMonths,
                'total_interest' => $interest,
                'total_amount' => $totalAmount,
                'purpose' => $data['purpose'] ?? null,
                'status' => 'pending',
                'applied_at' => $data['applied_at'],
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'guarantor_relationship' => $data['guarantor_relationship'] ?? null,
                'risk_score' => $riskScore,
                'decision' => $ruling['decision'],
                'decision_reason' => $ruling['reason'],
                'created_by' => $createdBy,
                'requested_via' => $requestedVia,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($requestedVia === Loan::REQUESTED_VIA_PORTAL) {
                app(AuditTrail::class)->record('loan.requested', $loan, [
                    'reference' => $loan->loan_reference,
                    'member_id' => $member->id,
                    'principal' => $principal,
                    'term_months' => $termMonths,
                ]);
            }

            return $loan;
        });
    }
}
