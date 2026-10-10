<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Services\CreditRiskFeatureExtractor;
use App\Services\CreditRiskScoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreditRiskScoringTest extends TestCase
{
    use RefreshDatabase;

    protected Sacco $sacco;

    protected User $staff;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create();
        $this->staff = User::factory()->create([
            'sacco_id' => $this->sacco->id,
            'role' => 'loan_officer',
        ]);

        $this->actingAs($this->staff);

        // Create a member who joined 12 months ago
        $this->member = Member::factory()->consented($this->staff)->create([
            'sacco_id' => $this->sacco->id,
            'join_date' => Carbon::now()->subMonths(12)->format('Y-m-d'),
        ]);
    }

    public function test_feature_extractor_computes_accurate_sacco_metrics(): void
    {
        // 1. Create 6 deposits of 200,000 and 1 withdrawal of 100,000
        for ($i = 0; $i < 6; $i++) {
            Saving::factory()->create([
                'sacco_id' => $this->sacco->id,
                'member_id' => $this->member->id,
                'type' => 'deposit',
                'amount' => 200000,
                'transaction_date' => Carbon::now()->subMonths(6 - $i)->format('Y-m-d'),
            ]);
        }

        Saving::factory()->create([
            'sacco_id' => $this->sacco->id,
            'member_id' => $this->member->id,
            'type' => 'withdrawal',
            'amount' => 100000,
            'transaction_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
        ]);

        // Net savings balance = (6 * 200,000) - 100,000 = 1,100,000
        $this->assertEquals(1100000.0, $this->member->savings_balance);

        // 2. One closed 2-month loan of 550,000 (two installments of 275,000), both paid by their due dates
        $disbursed = Carbon::now()->subMonths(4);
        $closedLoan = $this->loanWithTerms('closed', 2, 550000, $disbursed);

        $this->repay($closedLoan, 300000, $disbursed->copy()->addDays(10));
        $this->repay($closedLoan, 250000, $disbursed->copy()->addMonths(2)->subDays(3));

        // 3. Extract features for a requested principal of 2,200,000
        $extractor = app(CreditRiskFeatureExtractor::class);
        $features = $extractor->extractFeatures($this->member, 2200000);

        // 6 deposits / 12 months tenure = 0.5 deposits/month
        $this->assertEquals(0.5, $features['contribution_frequency']);
        // All deposits were exactly 200,000 -> zero variance, floored to the lowest value seen in training
        $this->assertEquals(0.05, $features['contribution_consistency']);
        $this->assertEquals(1100000.0, $features['savings_balance']);
        $this->assertEquals(12, $features['tenure_months']);
        $this->assertEquals(1, $features['past_loans_count']);
        $this->assertEquals(0, $features['past_default_count']);
        // Both installments were covered by their due dates
        $this->assertEquals(1.0, $features['on_time_repayment_rate']);
        // 2,200,000 / 1,100,000 = 2.0x leverage
        $this->assertEquals(2.0, $features['loan_to_savings_ratio']);
    }

    public function test_feature_extractor_handles_edge_cases_gracefully(): void
    {
        // New member joined today, zero savings, zero past loans
        $newMember = Member::factory()->create([
            'sacco_id' => $this->sacco->id,
            'join_date' => Carbon::now()->format('Y-m-d'),
        ]);

        $extractor = app(CreditRiskFeatureExtractor::class);
        $features = $extractor->extractFeatures($newMember, 500000);

        $this->assertEquals(0.0, $features['contribution_frequency']);
        // No deposits means no measurable pattern: must not look like a perfectly steady saver
        $this->assertEquals(CreditRiskFeatureExtractor::NO_HISTORY_CONSISTENCY, $features['contribution_consistency']);
        $this->assertEquals(0.0, $features['savings_balance']);
        $this->assertEquals(0, $features['tenure_months']);
        $this->assertEquals(0, $features['past_loans_count']);
        $this->assertEquals(0, $features['past_default_count']);
        // Neutral benchmark for new applicant
        $this->assertEquals(1.0, $features['on_time_repayment_rate']);
        // Zero savings yields maximum leverage penalty (10.0x) without division by zero
        $this->assertEquals(10.0, $features['loan_to_savings_ratio']);
    }

    public function test_feature_extractor_detects_past_defaults_and_late_repayments(): void
    {
        // A defaulted 3-month loan of 300,000, disbursed 5 months ago: installments fell due 4, 3 and 2 months ago
        $loan = $this->loanWithTerms('defaulted', 3, 300000, Carbon::now()->subMonths(5));

        // The only repayment came 20 days ago, after every due date
        $this->repay($loan, 100000, Carbon::now()->subDays(20));

        $features = app(CreditRiskFeatureExtractor::class)->extractFeatures($this->member, 1000000);

        $this->assertEquals(1, $features['past_default_count']);
        $this->assertEquals(0.0, $features['on_time_repayment_rate']); // 0 of 3 installments on time
    }

    public function test_a_defaulted_loan_that_is_later_repaid_and_closed_still_counts_as_a_past_default(): void
    {
        // 3-month loan of 300,000 disbursed 7 months ago: due 4 months ago, so well past the 90-day threshold
        $disbursed = Carbon::now()->subMonths(7);
        $loan = $this->loanWithTerms('disbursed', 3, 300000, $disbursed);
        $extractor = app(CreditRiskFeatureExtractor::class);

        // It is flagged as defaulted while unpaid
        $this->assertEquals('defaulted', $loan->refreshStatus());
        $loan->refresh();
        $this->assertTrue($loan->ever_defaulted);
        $this->assertEquals($loan->due_date->copy()->addDays(Loan::DEFAULT_THRESHOLD_DAYS + 1)->toDateString(), $loan->first_default_date->toDateString());
        $this->assertEquals(1, $extractor->extractFeatures($this->member, 500000)['past_default_count']);

        // The member then clears the whole balance and the loan closes
        $this->repay($loan, 300000, Carbon::now()->subDays(2));
        $this->assertEquals('closed', $loan->refresh()->refreshStatus());

        // Current status says repaid, but the default stays on the record and in the risk features
        $loan->refresh();
        $this->assertEquals('closed', $loan->status);
        $this->assertTrue($loan->ever_defaulted);
        $this->assertNotNull($loan->first_default_date);

        $features = $extractor->extractFeatures($this->member, 500000);
        $this->assertEquals(1, $features['past_default_count']);
        $this->assertEquals(1, $features['past_loans_count']);
        // The only payment came after every installment fell due: 0 of 3 on time
        $this->assertEquals(0.0, $features['on_time_repayment_rate']);
        $this->assertEquals(1, $this->member->fresh()->past_default_count);
    }

    public function test_a_loan_repaid_late_without_being_refreshed_in_between_is_still_recorded_as_defaulted(): void
    {
        // Nobody refreshed this loan while it was in default; it is repaid in full 20 days after the threshold passed
        $disbursed = Carbon::now()->subMonths(7);
        $loan = $this->loanWithTerms('disbursed', 3, 300000, $disbursed);
        $this->repay($loan, 300000, $loan->due_date->copy()->addDays(Loan::DEFAULT_THRESHOLD_DAYS + 20));

        $this->assertEquals('closed', $loan->refreshStatus());

        $loan->refresh();
        $this->assertTrue($loan->ever_defaulted);
        $this->assertEquals(1, app(CreditRiskFeatureExtractor::class)->extractFeatures($this->member, 500000)['past_default_count']);
    }

    public function test_a_loan_repaid_before_the_default_threshold_is_not_a_default(): void
    {
        // Overdue by 40 days when fully repaid: late, but never past the 90-day threshold
        $disbursed = Carbon::now()->subMonths(7);
        $loan = $this->loanWithTerms('disbursed', 3, 300000, $disbursed);
        $this->repay($loan, 300000, $loan->due_date->copy()->addDays(40));

        $this->assertEquals('closed', $loan->refreshStatus());

        $this->assertFalse($loan->refresh()->ever_defaulted);
        $this->assertNull($loan->first_default_date);
        $this->assertEquals(0, app(CreditRiskFeatureExtractor::class)->extractFeatures($this->member, 500000)['past_default_count']);
    }

    public function test_default_history_can_never_be_cleared_or_overwritten(): void
    {
        $loan = $this->loanWithTerms('defaulted', 3, 300000, Carbon::now()->subMonths(7));
        $originalDate = $loan->refresh()->first_default_date->toDateString();

        $loan->update(['ever_defaulted' => false, 'first_default_date' => null, 'status' => 'closed']);
        $this->assertTrue($loan->refresh()->ever_defaulted);
        $this->assertEquals($originalDate, $loan->first_default_date->toDateString());

        $loan->update(['first_default_date' => Carbon::today()]);
        $this->assertEquals($originalDate, $loan->refresh()->first_default_date->toDateString());
        $this->assertEquals('closed', $loan->status);
    }

    public function test_on_time_rate_is_measured_per_installment(): void
    {
        // 2-month loan of 200,000 disbursed 3 months ago: installments of 100,000 due 2 and 1 months ago
        $disbursed = Carbon::now()->subMonths(3);
        $loan = $this->loanWithTerms('overdue', 2, 200000, $disbursed);

        // First installment paid on time, second never paid
        $this->repay($loan, 100000, $disbursed->copy()->addDays(15));

        $rate = app(CreditRiskFeatureExtractor::class)->calculateOnTimeRepaymentRate($this->member);

        $this->assertEquals(0.5, $rate);
    }

    public function test_paying_the_whole_loan_only_at_maturity_counts_early_installments_as_late(): void
    {
        // 3-month loan of 300,000, fully paid in one lump on the final due date
        $disbursed = Carbon::now()->subMonths(4);
        $loan = $this->loanWithTerms('closed', 3, 300000, $disbursed);

        $this->repay($loan, 300000, $disbursed->copy()->addMonths(3));

        $rate = app(CreditRiskFeatureExtractor::class)->calculateOnTimeRepaymentRate($this->member);

        // Installments 1 and 2 were missed, only the last was met: 1 of 3
        $this->assertEquals(0.33, $rate);
    }

    public function test_installments_not_yet_due_do_not_count_against_the_member(): void
    {
        // Disbursed 10 days ago: first installment is not due yet
        $this->loanWithTerms('disbursed', 6, 600000, Carbon::now()->subDays(10));

        $this->assertEquals(1.0, app(CreditRiskFeatureExtractor::class)->calculateOnTimeRepaymentRate($this->member));
    }

    public function test_paying_off_a_loan_early_counts_as_on_time(): void
    {
        // 6-month loan disbursed 2 months ago, repaid in full after 3 weeks
        $disbursed = Carbon::now()->subMonths(2);
        $loan = $this->loanWithTerms('closed', 6, 600000, $disbursed);

        $this->repay($loan, 600000, $disbursed->copy()->addWeeks(3));

        $this->assertEquals(1.0, app(CreditRiskFeatureExtractor::class)->calculateOnTimeRepaymentRate($this->member));
    }

    public function test_contribution_consistency_is_neutral_for_one_deposit_and_clamped_to_training_range(): void
    {
        $extractor = app(CreditRiskFeatureExtractor::class);

        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => 100000]);
        $this->assertEquals(1.0, $extractor->calculateContributionConsistency($this->member));

        // One huge deposit among many tiny ones gives a raw CV above 3 (about 3.3); it must not exceed the training maximum
        foreach ([...array_fill(0, 9, 1000), 50000000] as $amount) {
            Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => $amount]);
        }
        $this->assertEquals(3.0, $extractor->calculateContributionConsistency($this->member->fresh()));
    }

    public function test_fallback_contributions_are_the_actual_points_behind_the_score(): void
    {
        Http::fake(['*/score' => Http::failedConnection()]);

        $features = [
            'contribution_frequency' => 0.5,
            'contribution_consistency' => 1.0,
            'savings_balance' => 100000.0,
            'tenure_months' => 3,
            'past_loans_count' => 1,
            'past_default_count' => 1,
            'on_time_repayment_rate' => 0.5,
            'loan_to_savings_ratio' => 5.0,
        ];

        $result = app(CreditRiskScoringService::class)->scoreFeatures($features);

        $this->assertFalse($result['probability_calibrated']);
        // 45 (default) + 20 (leverage: (5-3)*10) + 10.5 (repayment: 0.3*35) + 15 (new member)
        $this->assertEquals(['past_default_count' => 45.0, 'loan_to_savings_ratio' => 20.0, 'on_time_repayment_rate' => 10.5, 'tenure_months' => 15.0], $result['feature_contributions']);
        // 25 base + 90.5 points exceeds the scale, so the score is capped at 100
        $this->assertEquals(100.0, $result['risk_score']);
    }

    public function test_calibration_flag_is_passed_through_from_the_microservice(): void
    {
        Http::fake(['*/score' => Http::response([
            'risk_score' => 40.0, 'default_probability' => 0.06, 'probability_calibrated' => true,
            'risk_band' => 'medium', 'model_version' => '1.1.0', 'champion_algorithm' => 'logistic_regression',
            'feature_contributions' => ['past_default_count' => -1.2],
        ])]);

        $result = app(CreditRiskScoringService::class)->scoreMember($this->member, 100000);

        $this->assertTrue($result['probability_calibrated']);
        $this->assertEquals(0.06, $result['default_probability']);
        $this->assertEquals(['past_default_count' => -1.2], $result['feature_contributions']);
    }

    /**
     * Create a loan with explicit terms so installment maths is deterministic.
     */
    private function loanWithTerms(string $status, int $termMonths, float $total, Carbon $disbursed): Loan
    {
        return Loan::factory()->create([
            'sacco_id' => $this->sacco->id,
            'member_id' => $this->member->id,
            'status' => $status,
            'term_months' => $termMonths,
            'total_amount' => $total,
            'disbursed_at' => $disbursed,
            'due_date' => $disbursed->copy()->addMonths($termMonths)->format('Y-m-d'),
        ]);
    }

    private function repay(Loan $loan, float $amount, Carbon $date): void
    {
        Repayment::factory()->create([
            'sacco_id' => $this->sacco->id,
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'amount' => $amount,
            'repayment_date' => $date->format('Y-m-d'),
        ]);
    }

    public function test_scoring_service_communicates_with_ml_microservice(): void
    {
        // Fake the HTTP response from the FastAPI microservice
        Http::fake([
            '*/score' => Http::response([
                'status' => 'success',
                'risk_score' => 22.4,
                'default_probability' => 0.224,
                'risk_band' => 'low',
                'model_version' => '1.0.0',
                'champion_algorithm' => 'random_forest',
                'feature_contributions' => [
                    'past_default_count' => 0.0,
                    'loan_to_savings_ratio' => 5.0,
                ],
            ], 200),
        ]);

        $scoringService = app(CreditRiskScoringService::class);
        $result = $scoringService->scoreMember($this->member, 1000000);

        $this->assertTrue($result['success']);
        $this->assertEquals(22.4, $result['risk_score']);
        $this->assertEquals(0.224, $result['default_probability']);
        $this->assertEquals('low', $result['risk_band']);
        $this->assertEquals('random_forest', $result['champion_algorithm']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/score')
                && isset($request['contribution_frequency'])
                && isset($request['savings_balance'])
                && isset($request['loan_to_savings_ratio']);
        });
    }

    public function test_scoring_service_falls_back_gracefully_when_offline(): void
    {
        // Simulate connection refusal / offline ML microservice
        Http::fake([
            '*/score' => Http::failedConnection(),
        ]);

        $scoringService = app(CreditRiskScoringService::class);
        $result = $scoringService->scoreMember($this->member, 1500000);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertEquals('heuristic_fallback', $result['champion_algorithm']);
        $this->assertGreaterThanOrEqual(0.0, $result['risk_score']);
        $this->assertLessThanOrEqual(100.0, $result['risk_score']);
        $this->assertContains($result['risk_band'], ['low', 'medium', 'high']);
    }

    public function test_console_command_score_member(): void
    {
        Http::fake([
            '*/score' => Http::response([
                'status' => 'success',
                'risk_score' => 18.5,
                'default_probability' => 0.185,
                'risk_band' => 'low',
                'model_version' => '1.0.0',
                'champion_algorithm' => 'gradient_boosting',
                'feature_contributions' => ['repayment_history' => 0.0],
            ], 200),
        ]);

        $this->artisan('credit:score-member', [
            'member_id' => $this->member->id,
            'principal' => 1500000,
        ])
            ->expectsOutputToContain('SACCO ML CREDIT RISK ASSESSMENT ENGINE')
            ->expectsOutputToContain($this->member->full_name)
            ->expectsOutputToContain('18.5 / 100')
            ->expectsOutputToContain('LOW RISK')
            ->assertSuccessful();
    }
}
