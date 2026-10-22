<?php

namespace Tests\Feature;

use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Services\LendingDecisionEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LendingDecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an assessment array shaped like CreditRiskScoringService output.
     *
     * @param  array<string, mixed>  $features
     * @return array<string, mixed>
     */
    private function assessment(float $score, array $features = [], bool $success = true): array
    {
        return [
            'success' => $success,
            'risk_score' => $score,
            'risk_band' => 'low',
            'features' => array_merge([
                'savings_balance' => 1000000.0,
                'tenure_months' => 12,
                'past_default_count' => 0,
                'loan_to_savings_ratio' => 1.0,
            ], $features),
        ];
    }

    public function test_low_score_is_recommended_for_approval(): void
    {
        $result = (new LendingDecisionEngine)->decide($this->assessment(20.0), new LendingPolicy);

        $this->assertSame('approve', $result['decision']);
        $this->assertStringContainsString('below the approval limit', $result['reason']);
    }

    public function test_middle_score_requires_guarantor(): void
    {
        $result = (new LendingDecisionEngine)->decide($this->assessment(60.0), new LendingPolicy);

        $this->assertSame('require_guarantor', $result['decision']);
    }

    public function test_high_score_is_recommended_for_rejection(): void
    {
        $result = (new LendingDecisionEngine)->decide($this->assessment(75.0), new LendingPolicy);

        $this->assertSame('reject', $result['decision']);
    }

    public function test_custom_thresholds_change_the_outcome(): void
    {
        $policy = new LendingPolicy(['approve_below_score' => 70, 'reject_at_or_above_score' => 90]);

        $result = (new LendingDecisionEngine)->decide($this->assessment(60.0), $policy);

        $this->assertSame('approve', $result['decision']);
    }

    public function test_hard_rules_escalate_a_low_score(): void
    {
        $engine = new LendingDecisionEngine;
        $policy = new LendingPolicy;

        $this->assertSame('require_guarantor', $engine->decide($this->assessment(10.0, ['loan_to_savings_ratio' => 5.0]), $policy)['decision']);
        $this->assertSame('require_guarantor', $engine->decide($this->assessment(10.0, ['tenure_months' => 1]), $policy)['decision']);
        $this->assertSame('require_guarantor', $engine->decide($this->assessment(10.0, ['past_default_count' => 1]), $policy)['decision']);

        $strict = new LendingPolicy(['reject_on_past_default' => true]);
        $this->assertSame('reject', $engine->decide($this->assessment(10.0, ['past_default_count' => 1]), $strict)['decision']);
    }

    public function test_null_ratio_cap_disables_the_ratio_rule(): void
    {
        $policy = new LendingPolicy(['max_loan_to_savings_ratio' => null]);

        $result = (new LendingDecisionEngine)->decide($this->assessment(10.0, ['loan_to_savings_ratio' => 50.0]), $policy);

        $this->assertSame('approve', $result['decision']);
    }

    public function test_fallback_scoring_is_disclosed_in_the_explanation(): void
    {
        $result = (new LendingDecisionEngine)->decide($this->assessment(20.0, [], success: false), new LendingPolicy);

        $this->assertStringContainsString('simplified estimate', $result['reason']);
    }

    public function test_loan_application_stores_decision_and_reason(): void
    {
        Http::fake(['*/score' => Http::response([
            'risk_score' => 90.0, 'default_probability' => 0.9, 'risk_band' => 'high',
            'model_version' => '1.0.0', 'champion_algorithm' => 'logistic_regression', 'feature_contributions' => [],
        ])]);

        [$officer, $member] = $this->tenantWithMember();

        $this->actingAs($officer)->post(route('tenant.loans.store'), $this->loanPayload($member))
            ->assertRedirect();

        $loan = Loan::firstOrFail();
        $this->assertSame('reject', $loan->decision);
        $this->assertStringContainsString('rejection threshold', $loan->decision_reason);
    }

    public function test_approving_against_the_recommendation_requires_a_reason(): void
    {
        [$officer, $member] = $this->tenantWithMember();
        $loan = Loan::factory()->create([
            'sacco_id' => $officer->sacco_id,
            'member_id' => $member->id,
            'status' => 'pending',
            'decision' => 'reject',
        ]);

        $this->actingAs($officer)->post(route('tenant.loans.approve', $loan))
            ->assertSessionHasErrors('notes');
        $this->assertSame('pending', $loan->fresh()->status);

        $this->actingAs($officer)->post(route('tenant.loans.approve', $loan), ['notes' => 'Committee accepted collateral.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_approving_a_recommended_loan_needs_no_reason(): void
    {
        [$officer, $member] = $this->tenantWithMember();
        $loan = Loan::factory()->create([
            'sacco_id' => $officer->sacco_id,
            'member_id' => $member->id,
            'status' => 'pending',
            'decision' => 'approve',
        ]);

        $this->actingAs($officer)->post(route('tenant.loans.approve', $loan))->assertSessionHasNoErrors();
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_only_sacco_admins_can_edit_the_policy_and_changes_are_tenant_scoped(): void
    {
        $sacco = Sacco::factory()->create();
        $other = Sacco::factory()->create();
        $admin = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $officer = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'loan_officer', 'status' => 'active']);

        $payload = [
            'approve_below_score' => 40,
            'reject_at_or_above_score' => 80,
            'max_loan_to_savings_ratio' => 2.5,
            'min_tenure_months' => 6,
            'reject_on_past_default' => 1,
        ];

        $this->actingAs($officer)->put(route('tenant.lending_policy.update'), $payload)->assertForbidden();

        $this->actingAs($admin)->get(route('tenant.lending_policy.edit'))->assertOk();
        $this->actingAs($admin)->put(route('tenant.lending_policy.update'), $payload)
            ->assertRedirect(route('tenant.lending_policy.edit'));

        $policy = LendingPolicy::resolveFor($sacco->id);
        $this->assertEquals(40.0, $policy->approve_below_score);
        $this->assertTrue($policy->reject_on_past_default);
        $this->assertEquals(75.0, LendingPolicy::resolveFor($other->id)->reject_at_or_above_score);
    }

    public function test_policy_rejects_inverted_thresholds(): void
    {
        $admin = User::factory()->create(['sacco_id' => Sacco::factory()->create()->id, 'role' => 'sacco_admin', 'status' => 'active']);

        $this->actingAs($admin)->put(route('tenant.lending_policy.update'), [
            'approve_below_score' => 80,
            'reject_at_or_above_score' => 60,
            'min_tenure_months' => 3,
        ])->assertSessionHasErrors('reject_at_or_above_score');
    }

    /**
     * @return array{0: User, 1: Member}
     */
    private function tenantWithMember(): array
    {
        $sacco = Sacco::factory()->create();
        $officer = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $member = Member::factory()->consented($officer)->create([
            'sacco_id' => $sacco->id,
            'status' => 'active',
            'join_date' => Carbon::now()->subMonths(12)->format('Y-m-d'),
        ]);
        Saving::factory()->create([
            'sacco_id' => $sacco->id,
            'member_id' => $member->id,
            'type' => 'deposit',
            'amount' => 1000000,
        ]);

        return [$officer, $member];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanPayload(Member $member): array
    {
        return [
            'member_id' => $member->id,
            'principal' => 500000,
            'interest_rate' => 10,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'applied_at' => Carbon::now()->format('Y-m-d'),
        ];
    }
}
