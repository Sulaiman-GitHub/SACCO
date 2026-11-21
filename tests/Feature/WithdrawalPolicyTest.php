<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Models\WithdrawalPolicy;
use App\Services\AuditTrail;
use App\Services\WithdrawalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    private User $officer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->member = Member::factory()->consented($this->admin)->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validRules(array $overrides = []): array
    {
        return array_merge([
            'notice_days' => 5,
            'loan_cover_mode' => 'keep_ratio_cover',
            'early_withdrawal_fee_percent' => 2.5,
            'daily_limit_per_member' => 800000,
            'admin_approval_threshold' => 300000,
            'security_hold_days' => 2,
            'request_expiry_days' => 14,
            'payer_must_differ_from_approver' => 'always',
        ], $overrides);
    }

    private function fund(float $amount, ?Member $member = null): void
    {
        Saving::factory()->create([
            'sacco_id' => $this->sacco->id,
            'member_id' => ($member ?? $this->member)->id,
            'type' => 'deposit',
            'amount' => $amount,
        ]);
    }

    public function test_a_sacco_without_a_saved_row_uses_the_defaults(): void
    {
        $policy = WithdrawalPolicy::resolveFor($this->sacco->id);

        $this->assertFalse($policy->exists);
        $this->assertSame(7, $policy->notice_days);
        $this->assertSame('block_while_loan_active', $policy->loan_cover_mode);
        $this->assertSame(0.0, $policy->early_withdrawal_fee_percent);
        $this->assertSame(1000000.0, $policy->daily_limit_per_member);
        $this->assertSame(500000.0, $policy->admin_approval_threshold);
        $this->assertSame(3, $policy->security_hold_days);
        $this->assertSame(30, $policy->request_expiry_days);
        $this->assertFalse($policy->offersEarlyWithdrawal());

        $this->actingAs($this->admin)->get(route('tenant.withdrawal_policy.edit'))->assertOk()->assertSee('Withdrawal rules for');
    }

    public function test_an_admin_saves_the_rules_and_the_change_is_audited_with_old_and_new_values(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tenant.withdrawal_policy.update'), $this->validRules())
            ->assertRedirect(route('tenant.withdrawal_policy.edit'))
            ->assertSessionHasNoErrors();

        $saved = WithdrawalPolicy::resolveFor($this->sacco->id);
        $this->assertTrue($saved->exists);
        $this->assertSame(5, $saved->notice_days);
        $this->assertSame('keep_ratio_cover', $saved->loan_cover_mode);
        $this->assertTrue($saved->offersEarlyWithdrawal());

        $log = AuditLog::withoutTenantScope()->where('action', 'withdrawal_policy.updated')->latest('id')->firstOrFail();
        $changes = $log->detailsArray()['changes'];
        $this->assertSame([7, 5], $changes['notice_days']);
        $this->assertSame([0, 2.5], $changes['early_withdrawal_fee_percent']);
        $this->assertSame('Withdrawal rules updated', $log->actionName());
        $this->assertContains('Notice before a withdrawal (days)', array_column($log->displayDetails(), 'label'));
        $this->assertContains('No withdrawals while a loan is outstanding to Savings must keep covering the loan', array_column($log->displayDetails(), 'value'));

        // A second save changes only what changed
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules(['notice_days' => 10]));
        $second = AuditLog::withoutTenantScope()->where('action', 'withdrawal_policy.updated')->latest('id')->firstOrFail();
        $this->assertSame(['notice_days'], array_keys($second->detailsArray()['changes']));
        $this->assertSame([5, 10], $second->detailsArray()['changes']['notice_days']);

        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_an_unchanged_save_writes_no_audit_entry(): void
    {
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules());
        $count = AuditLog::withoutTenantScope()->where('action', 'withdrawal_policy.updated')->count();

        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules());

        $this->assertSame($count, AuditLog::withoutTenantScope()->where('action', 'withdrawal_policy.updated')->count());
    }

    public function test_blank_limit_and_threshold_mean_no_limit_and_administrator_always(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tenant.withdrawal_policy.update'), $this->validRules(['daily_limit_per_member' => '', 'admin_approval_threshold' => '']))
            ->assertSessionHasNoErrors();

        $saved = WithdrawalPolicy::resolveFor($this->sacco->id);
        $this->assertNull($saved->daily_limit_per_member);
        $this->assertNull($saved->admin_approval_threshold);
        $this->assertTrue($saved->needsAdminApproval(1000));
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([
            ['early_withdrawal_fee_percent' => 20.5],
            ['early_withdrawal_fee_percent' => -1],
            ['notice_days' => -1],
            ['notice_days' => 91],
            ['loan_cover_mode' => 'anything'],
            ['security_hold_days' => 31],
            ['request_expiry_days' => 0],
            ['daily_limit_per_member' => 5],
            ['admin_approval_threshold' => -5],
            ['notice_days' => 'abc'],
        ] as $bad) {
            $this->actingAs($this->admin)
                ->put(route('tenant.withdrawal_policy.update'), $this->validRules($bad))
                ->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertFalse(WithdrawalPolicy::resolveFor($this->sacco->id)->exists);
    }

    public function test_a_loan_officer_cannot_see_or_change_the_rules(): void
    {
        $this->actingAs($this->officer)->get(route('tenant.withdrawal_policy.edit'))->assertForbidden();
        $this->actingAs($this->officer)->put(route('tenant.withdrawal_policy.update'), $this->validRules())->assertForbidden();
        $this->assertFalse(WithdrawalPolicy::resolveFor($this->sacco->id)->exists);
    }

    public function test_one_saccos_rules_never_leak_into_another(): void
    {
        $other = Sacco::factory()->create(['status' => 'active']);
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules());

        $this->assertSame(5, WithdrawalPolicy::resolveFor($this->sacco->id)->notice_days);
        $this->assertSame(7, WithdrawalPolicy::resolveFor($other->id)->notice_days);
        $this->assertSame(1, WithdrawalPolicy::withoutTenantScope()->count());
    }

    public function test_changing_the_phone_stamps_it_but_reformatting_does_not(): void
    {
        $this->member->forceFill(['phone' => '0772123456'])->saveQuietly();
        $this->member->update(['phone' => '+256 772 123 456']);
        $this->assertNull($this->member->fresh()->phone_changed_at);

        $this->member->update(['phone' => '0701234567']);
        $this->assertNotNull($this->member->fresh()->phone_changed_at);
    }

    public function test_portal_password_changes_are_stamped_and_the_columns_are_not_mass_assignable(): void
    {
        $this->assertNull($this->member->fresh()->portal_password_changed_at);

        $temporary = $this->member->issuePortalCredentials();
        $this->assertNotNull($this->member->fresh()->portal_password_changed_at);

        $this->member->forceFill(['portal_password_changed_at' => null])->saveQuietly();
        $this->member->changePortalPassword('Chosen123pass');
        $this->assertNotNull($this->member->fresh()->portal_password_changed_at);
        $this->assertNotSame($temporary, 'Chosen123pass');

        $fresh = Member::withoutTenantScope()->findOrFail($this->member->id);
        $fresh->fill(['portal_password_changed_at' => null, 'phone_changed_at' => null]);
        $this->assertNotNull($fresh->portal_password_changed_at);
    }

    public function test_a_counter_withdrawal_is_blocked_while_a_loan_is_outstanding(): void
    {
        $this->fund(500000);
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 600000]);

        $this->actingAs($this->officer)->post(route('tenant.savings.store'), [
            'member_id' => $this->member->id,
            'type' => 'withdrawal',
            'amount' => 50000,
            'payment_method' => 'cash',
            'transaction_date' => now()->toDateString(),
        ])->assertSessionHasErrors(['amount' => 'Savings cannot be withdrawn while a loan is outstanding. They back the loan until it is fully repaid.']);

        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());

        // Deposits are never blocked
        $this->actingAs($this->officer)->post(route('tenant.savings.store'), [
            'member_id' => $this->member->id,
            'type' => 'deposit',
            'amount' => 20000,
            'payment_method' => 'cash',
            'transaction_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_a_closed_loan_no_longer_blocks_and_a_defaulted_one_does(): void
    {
        $this->fund(500000);
        $loan = Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 600000, 'status' => 'defaulted']);
        $policy = WithdrawalPolicy::resolveFor($this->sacco->id);

        $this->assertNotNull($policy->loanCoverError($this->member, 10000, 500000));

        $loan->forceFill(['status' => 'closed'])->saveQuietly();
        $this->assertNull($policy->loanCoverError($this->member, 10000, 500000));
    }

    public function test_keep_ratio_mode_lets_the_member_withdraw_only_what_is_above_the_cover(): void
    {
        LendingPolicy::resolveFor($this->sacco->id)->update(['max_loan_to_savings_ratio' => 3.0]);
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules(['daily_limit_per_member' => '']));
        $this->fund(500000);
        // Loan 900,000 / ratio 3 = 300,000 must stay in savings, so at most 200,000 may leave
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 900000]);

        $payload = ['member_id' => $this->member->id, 'type' => 'withdrawal', 'payment_method' => 'cash', 'transaction_date' => now()->toDateString()];

        $this->actingAs($this->officer)->post(route('tenant.savings.store'), $payload + ['amount' => 200001])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());

        $this->actingAs($this->officer)->post(route('tenant.savings.store'), $payload + ['amount' => 200000])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_keep_ratio_mode_without_a_ratio_behaves_like_block_mode(): void
    {
        LendingPolicy::resolveFor($this->sacco->id)->update(['max_loan_to_savings_ratio' => null]);
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules());
        $this->fund(500000);
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 100000]);

        $this->assertStringContainsString('cannot be withdrawn while a loan', (string) WithdrawalPolicy::resolveFor($this->sacco->id)->loanCoverError($this->member, 5000, 500000));
    }

    public function test_the_counter_applies_the_daily_limit_by_transaction_date(): void
    {
        $this->actingAs($this->admin)->put(route('tenant.withdrawal_policy.update'), $this->validRules(['daily_limit_per_member' => 100000]));
        $this->fund(900000);
        $payload = ['member_id' => $this->member->id, 'type' => 'withdrawal', 'payment_method' => 'cash', 'transaction_date' => now()->toDateString()];

        $this->actingAs($this->officer)->post(route('tenant.savings.store'), $payload + ['amount' => 60000])->assertSessionHasNoErrors();
        $this->actingAs($this->officer)->post(route('tenant.savings.store'), $payload + ['amount' => 50000])->assertSessionHasErrors('amount');
        $this->actingAs($this->officer)->post(route('tenant.savings.store'), $payload + ['amount' => 40000])->assertSessionHasNoErrors();

        // Another day has its own allowance
        $this->actingAs($this->officer)->post(route('tenant.savings.store'), array_merge($payload, ['amount' => 90000, 'transaction_date' => now()->subDay()->toDateString()]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_demo_seed_switches_the_security_hold_off_without_overwriting_saved_rules(): void
    {
        $this->seed();

        $demo = Sacco::where('license_number', 'UMRA/SACCO/2026/001')->firstOrFail();
        $policy = WithdrawalPolicy::withoutTenantScope()->where('sacco_id', $demo->id)->firstOrFail();
        $this->assertSame(0, $policy->security_hold_days);
        $this->assertSame(7, $policy->notice_days);

        // A fresh member whose portal password was set a moment ago can ask straight away
        $admin = User::withoutTenantScope()->where('sacco_id', $demo->id)->where('role', 'sacco_admin')->firstOrFail();
        $member = Member::factory()->consented($admin)->create(['sacco_id' => $demo->id, 'status' => 'active', 'phone' => '0772123456']);
        Saving::factory()->create(['sacco_id' => $demo->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 300000]);
        $member->issuePortalCredentials();
        $member->changePortalPassword('Chosen123pass');

        $request = app(WithdrawalRequestService::class)->submit($member->fresh(), 50000, false, null);
        $this->assertSame('pending', $request->status);

        // Running the seeder again keeps what an admin saved
        $policy->forceFill(['security_hold_days' => 4])->save();
        $this->seed();
        $this->assertSame(4, $policy->fresh()->security_hold_days);
    }
}
