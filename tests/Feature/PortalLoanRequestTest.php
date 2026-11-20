<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalLoanRequestTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/score' => Http::response([
            'risk_score' => 61.0, 'default_probability' => 0.2, 'probability_calibrated' => true,
            'risk_band' => 'medium', 'model_version' => 'test-1', 'champion_algorithm' => 'logistic_regression',
            'feature_contributions' => [],
        ])]);

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->member = Member::factory()->consented($this->admin)->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
        $this->member->issuePortalCredentials();
        $this->member->forceFill(['portal_must_change_password' => false])->save();
    }

    private function signInAsMember(Member $member): static
    {
        $this->actingAs($member->fresh(), 'member');
        Auth::shouldUse('web');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['principal' => 200000, 'term_months' => 6, 'purpose' => 'School fees'], $overrides);
    }

    public function test_member_can_request_a_loan_priced_from_the_sacco_policy(): void
    {
        LendingPolicy::resolveFor($this->sacco->id)->update(['default_interest_rate' => 12, 'default_interest_calculation_type' => 'simple']);

        $this->signInAsMember($this->member)->get(route('portal.loan_requests.create'))->assertOk();

        $this->post(route('portal.loan_requests.store'), $this->payload())
            ->assertRedirect(route('portal.loans'))
            ->assertSessionHasNoErrors();

        $loan = Loan::withoutTenantScope()->firstOrFail();
        $this->assertSame('pending', $loan->status);
        $this->assertSame(Loan::REQUESTED_VIA_PORTAL, $loan->requested_via);
        $this->assertNull($loan->created_by);
        $this->assertSame($this->sacco->id, $loan->sacco_id);
        $this->assertSame($this->member->id, $loan->member_id);
        $this->assertEquals(12.0, (float) $loan->interest_rate);
        $this->assertSame('simple', $loan->interest_calculation_type);
        $this->assertEquals(12000.0, (float) $loan->total_interest); // 200,000 x 12% x 6/12
        $this->assertSame(now()->toDateString(), $loan->applied_at->toDateString());
        // Scored and given a recommendation exactly like the staff flow, but stored only
        $this->assertEquals(61.0, (float) $loan->risk_score);
        $this->assertNotNull($loan->decision);
    }

    public function test_pricing_and_status_cannot_be_tampered_with(): void
    {
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload([
            'interest_rate' => 0,
            'interest_calculation_type' => 'simple',
            'status' => 'approved',
            'applied_at' => '2020-01-01',
            'sacco_id' => 999,
            'member_id' => 999,
            'risk_score' => 1,
            'decision' => 'approve',
            'created_by' => $this->admin->id,
            'requested_via' => 'staff',
        ]))->assertSessionHasNoErrors();

        $loan = Loan::withoutTenantScope()->firstOrFail();
        $this->assertEquals(10.0, (float) $loan->interest_rate); // policy default, not the 0 sent
        $this->assertSame('flat', $loan->interest_calculation_type);
        $this->assertSame('pending', $loan->status);
        $this->assertSame(now()->toDateString(), $loan->applied_at->toDateString());
        $this->assertSame($this->sacco->id, $loan->sacco_id);
        $this->assertSame($this->member->id, $loan->member_id);
        $this->assertEquals(61.0, (float) $loan->risk_score);
        $this->assertNull($loan->created_by);
        $this->assertSame(Loan::REQUESTED_VIA_PORTAL, $loan->requested_via);
    }

    public function test_amount_and_term_limits_are_enforced(): void
    {
        $this->signInAsMember($this->member);

        $this->post(route('portal.loan_requests.store'), $this->payload(['principal' => 9999]))->assertSessionHasErrors('principal');
        $this->post(route('portal.loan_requests.store'), $this->payload(['term_months' => 0]))->assertSessionHasErrors('term_months');
        $this->post(route('portal.loan_requests.store'), $this->payload(['term_months' => 121]))->assertSessionHasErrors('term_months');
        $this->assertSame(0, Loan::withoutTenantScope()->count());

        $this->post(route('portal.loan_requests.store'), $this->payload(['principal' => 10000, 'term_months' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(1, Loan::withoutTenantScope()->count());
    }

    public function test_a_second_pending_request_is_blocked_until_the_first_is_decided(): void
    {
        $this->signInAsMember($this->member);

        $this->post(route('portal.loan_requests.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('portal.loan_requests.store'), $this->payload())->assertSessionHasErrors('principal');
        $this->assertSame(1, Loan::withoutTenantScope()->count());

        Loan::withoutTenantScope()->firstOrFail()->reject($this->admin, 'Not enough savings.');

        $this->post(route('portal.loan_requests.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(2, Loan::withoutTenantScope()->count());
    }

    public function test_an_inactive_member_cannot_request_a_loan(): void
    {
        $this->member->forceFill(['status' => 'inactive'])->save();

        // The portal signs inactive members out before the request is even looked at
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload());

        $this->assertSame(0, Loan::withoutTenantScope()->count());
    }

    public function test_a_member_without_scoring_consent_still_creates_an_unscored_request(): void
    {
        $declined = Member::factory()->consented($this->admin, false)->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
        $declined->issuePortalCredentials();
        $declined->forceFill(['portal_must_change_password' => false])->save();
        $noRecord = Member::factory()->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
        $noRecord->issuePortalCredentials();
        $noRecord->forceFill(['portal_must_change_password' => false])->save();

        foreach ([$declined, $noRecord] as $member) {
            $this->signInAsMember($member)->post(route('portal.loan_requests.store'), $this->payload())->assertSessionHasNoErrors();
        }

        $loans = Loan::withoutTenantScope()->get();
        $this->assertCount(2, $loans);
        foreach ($loans as $loan) {
            $this->assertNull($loan->risk_score);
            $this->assertNull($loan->decision);
            $this->assertSame('pending', $loan->status);
        }
        Http::assertNothingSent();
    }

    public function test_a_member_only_ever_requests_a_loan_for_themselves_in_their_own_sacco(): void
    {
        $otherSacco = Sacco::factory()->create(['status' => 'active']);
        $otherMember = Member::factory()->create(['sacco_id' => $otherSacco->id, 'status' => 'active']);
        LendingPolicy::resolveFor($otherSacco->id)->update(['default_interest_rate' => 25]);

        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload(['member_id' => $otherMember->id, 'sacco_id' => $otherSacco->id]));

        $this->assertSame(0, Loan::withoutTenantScope()->where('member_id', $otherMember->id)->count());
        $loan = Loan::withoutTenantScope()->firstOrFail();
        $this->assertSame($this->sacco->id, $loan->sacco_id);
        $this->assertEquals(10.0, (float) $loan->interest_rate);
    }

    public function test_staff_see_a_member_request_badge_and_can_filter_for_it(): void
    {
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload());
        $staffLoan = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'closed']);
        $requested = Loan::withoutTenantScope()->where('requested_via', 'member_portal')->firstOrFail();

        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');

        $this->get(route('tenant.loans.index'))->assertOk()->assertSee('Member request');

        $this->get(route('tenant.loans.index', ['source' => 'member_portal']))
            ->assertOk()
            ->assertSee($requested->loan_reference)
            ->assertDontSee($staffLoan->loan_reference);

        $this->get(route('tenant.loans.show', $requested))->assertOk()->assertSee('from their portal');
    }

    public function test_the_portal_never_shows_the_score_or_recommendation(): void
    {
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload());
        $loan = Loan::withoutTenantScope()->firstOrFail();
        $loan->forceFill(['decision_reason' => 'SECRET-REASON-TEXT', 'notes' => 'SECRET-NOTE'])->save();

        foreach ([route('portal.loans'), route('portal.loan_requests.create')] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('61.0')
                ->assertDontSee('SECRET-REASON-TEXT')
                ->assertDontSee('SECRET-NOTE')
                ->assertDontSee('Recommend')
                ->assertDontSee('risk score')->assertDontSee('credit risk');
        }
    }

    public function test_the_member_sees_the_status_and_the_officers_rejection_reason(): void
    {
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload());
        $loan = Loan::withoutTenantScope()->firstOrFail();

        $this->get(route('portal.loans'))->assertSee('PENDING')->assertSee('has not been decided yet');

        $loan->reject($this->admin, 'Please clear your earlier balance first.');
        $this->get(route('portal.loans'))->assertSee('REJECTED')->assertSee('Please clear your earlier balance first.');
    }

    public function test_requesting_a_loan_is_audited_and_the_chain_still_verifies(): void
    {
        $this->signInAsMember($this->member)->post(route('portal.loan_requests.store'), $this->payload());

        $entry = AuditLog::withoutTenantScope()->where('action', 'loan.requested')->firstOrFail();
        $this->assertSame($this->sacco->id, (int) $entry->sacco_id);
        $this->assertNull($entry->user_id);
        $this->assertSame(200000.0, (float) $entry->detailsArray()['principal']);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_the_request_page_needs_a_portal_sign_in_and_staff_cannot_use_it(): void
    {
        $this->get(route('portal.loan_requests.create'))->assertRedirect(route('portal.login'));
        $this->post(route('portal.loan_requests.store'), $this->payload())->assertRedirect(route('portal.login'));
        $this->assertSame(0, Loan::withoutTenantScope()->count());
    }

    public function test_policy_settings_expose_and_audit_the_default_pricing(): void
    {
        $this->actingAs($this->admin);
        Auth::shouldUse('web');

        $payload = [
            'approve_below_score' => 50, 'reject_at_or_above_score' => 75, 'max_loan_to_savings_ratio' => 3,
            'min_tenure_months' => 3, 'default_interest_rate' => 14.5, 'default_interest_calculation_type' => 'simple',
        ];

        $this->get(route('tenant.lending_policy.edit'))->assertOk()->assertSee('Interest rate per year');
        $this->put(route('tenant.lending_policy.update'), $payload)->assertSessionHasNoErrors();

        $policy = LendingPolicy::resolveFor($this->sacco->id);
        $this->assertEquals(14.5, $policy->default_interest_rate);
        $this->assertSame('simple', $policy->default_interest_calculation_type);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'policy.updated')->count());

        $this->put(route('tenant.lending_policy.update'), array_merge($payload, ['default_interest_rate' => 101]))->assertSessionHasErrors('default_interest_rate');
        $this->put(route('tenant.lending_policy.update'), array_merge($payload, ['default_interest_calculation_type' => 'compound']))->assertSessionHasErrors('default_interest_calculation_type');
    }
}
