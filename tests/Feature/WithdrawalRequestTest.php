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
use App\Models\WithdrawalRequest;
use App\Services\AuditTrail;
use App\Services\WithdrawalRequestService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WithdrawalRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Chosen123pass';

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
        $this->member = $this->makeMember($this->sacco, 2000000);
    }

    /**
     * A portal-ready member with a chosen password, a valid phone and some savings.
     */
    private function makeMember(Sacco $sacco, float $savings, string $phone = '0772123456'): Member
    {
        $admin = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $member = Member::factory()->consented($admin)->create(['sacco_id' => $sacco->id, 'status' => 'active', 'phone' => $phone]);
        $member->issuePortalCredentials();
        $member->changePortalPassword(self::PASSWORD);
        $member->forceFill(['portal_password_changed_at' => null, 'phone_changed_at' => null])->saveQuietly();

        if ($savings > 0) {
            Saving::factory()->create(['sacco_id' => $sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => $savings]);
        }

        return $member->fresh();
    }

    private function asMember(Member $member): static
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($member->fresh(), 'member');
        Auth::shouldUse('web');

        return $this;
    }

    private function asStaff(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function policy(array $overrides = [], ?Sacco $sacco = null): WithdrawalPolicy
    {
        return WithdrawalPolicy::withoutTenantScope()->updateOrCreate(['sacco_id' => ($sacco ?? $this->sacco)->id], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(array $overrides = [], ?Member $member = null)
    {
        return $this->asMember($member ?? $this->member)->post(route('portal.withdrawals.store'), array_merge([
            'amount' => 100000,
            'password' => self::PASSWORD,
        ], $overrides));
    }

    private function openRequest(?Member $member = null): WithdrawalRequest
    {
        return WithdrawalRequest::withoutTenantScope()->where('member_id', ($member ?? $this->member)->id)->latest('id')->firstOrFail();
    }

    private function approvedRequest(float $amount = 100000): WithdrawalRequest
    {
        $this->policy(['notice_days' => 0]);
        $this->submit(['amount' => $amount])->assertSessionHasNoErrors();
        $request = $this->openRequest();
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();

        return $request->fresh();
    }

    public function test_full_flow_from_request_to_payout_creates_exactly_one_ledger_row(): void
    {
        $this->submit()->assertSessionHasNoErrors()->assertRedirect();

        $request = $this->openRequest();
        $this->assertSame('pending', $request->status);
        $this->assertStringStartsWith('WDR-'.date('Y').'-', $request->reference);
        $this->assertSame('100000.00', $request->amount);
        $this->assertSame('+256772123456', $request->destination_phone);
        $this->assertSame(now()->addDays(7)->toDateString(), $request->available_from->toDateString());
        $this->assertSame('100000.00', $request->net_amount);
        $this->assertFalse($request->early);

        // Notice not over yet: approval refused
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasErrors('request');
        $this->assertSame('pending', $request->fresh()->status);

        $this->travelTo(now()->addDays(7));
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($this->officer->id, $request->fresh()->decided_by);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'mtn_momo', 'payout_reference' => 'MM123456'])
            ->assertSessionHasNoErrors();

        $request = $request->fresh();
        $this->assertSame('paid', $request->status);
        $this->assertNotNull($request->saving_id);
        $withdrawals = Saving::withoutTenantScope()->where('type', 'withdrawal')->get();
        $this->assertCount(1, $withdrawals);
        $this->assertSame($request->saving_id, $withdrawals->first()->id);
        $this->assertSame('mtn_momo', $withdrawals->first()->payment_method);
        $this->assertSame('MM123456', $withdrawals->first()->payment_reference);
        $this->assertSame('100000.00', $withdrawals->first()->amount);
        $this->assertEquals(1900000.0, $this->member->fresh()->savings_balance);

        foreach (['withdrawal.requested', 'withdrawal.approved', 'withdrawal.paid'] as $action) {
            $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', $action)->count(), $action);
        }
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);

        $this->asMember($this->member)->get(route('portal.withdrawals.show', $request->id))->assertOk()->assertSee('Paid');
    }

    public function test_amount_member_phone_and_status_can_only_come_from_the_server(): void
    {
        $other = $this->makeMember($this->sacco, 500000, '0701111111');

        $this->submit([
            'member_id' => $other->id,
            'sacco_id' => 999,
            'destination_phone' => '0799999999',
            'phone' => '0799999999',
            'fee_amount' => 0,
            'net_amount' => 999999,
            'status' => 'paid',
            'available_from' => now()->toDateString(),
            'decided_by' => $this->admin->id,
            'saving_id' => 1,
        ])->assertSessionHasNoErrors();

        $request = $this->openRequest();
        $this->assertSame($this->member->id, $request->member_id);
        $this->assertSame($this->sacco->id, $request->sacco_id);
        $this->assertSame('+256772123456', $request->destination_phone);
        $this->assertSame('pending', $request->status);
        $this->assertSame('100000.00', $request->net_amount);
        $this->assertSame(now()->addDays(7)->toDateString(), $request->available_from->toDateString());
        $this->assertNull($request->decided_by);
        $this->assertNull($request->saving_id);
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->where('member_id', $other->id)->count());
    }

    public function test_staff_cannot_change_the_amount_or_destination_when_deciding(): void
    {
        $request = $this->approvedRequest(100000);

        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $request), [
            'payout_method' => 'cash', 'amount' => 5000, 'destination_phone' => '0700000000', 'member_id' => 99,
        ])->assertSessionHasNoErrors();

        $saving = Saving::withoutTenantScope()->where('type', 'withdrawal')->firstOrFail();
        $this->assertSame('100000.00', $saving->amount);
        $this->assertSame($this->member->id, $saving->member_id);
        $this->assertSame('+256772123456', $request->fresh()->destination_phone);

        $names = collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter(fn ($n) => str_starts_with((string) $n, 'tenant.withdrawals.'))->values()->all();
        $this->assertEqualsCanonicalizing(['tenant.withdrawals.index', 'tenant.withdrawals.show', 'tenant.withdrawals.approve', 'tenant.withdrawals.reject', 'tenant.withdrawals.pay'], $names);
    }

    public function test_a_wrong_password_is_rejected_and_repeated_failures_lock_the_form(): void
    {
        RateLimiter::clear('withdrawal-password:'.$this->member->id);

        for ($i = 0; $i < 5; $i++) {
            $this->submit(['password' => 'wrong-password'])->assertSessionHasErrors(['password' => 'That password is not correct.']);
        }
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        // The correct password no longer gets through while locked
        $this->submit()->assertSessionHasErrors('password');
        $this->assertStringContainsString('Too many incorrect passwords', session('errors')->first('password'));
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        RateLimiter::clear('withdrawal-password:'.$this->member->id);
        $this->travelTo(now()->addMinutes(2)); // past the per-minute request limit as well
        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(1, WithdrawalRequest::withoutTenantScope()->count());
    }

    public function test_the_password_is_required_and_never_sent_back_in_the_form(): void
    {
        $this->submit(['password' => ''])->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));

        $this->submit(['password' => 'wrong-password', 'amount' => 123456]);
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_a_member_with_no_usable_phone_cannot_request(): void
    {
        $this->member->forceFill(['phone' => '12345'])->saveQuietly();

        $this->submit()->assertSessionHasErrors('amount');
        $this->assertStringContainsString('no valid mobile number', session('errors')->first('amount'));
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        $this->asMember($this->member)->get(route('portal.withdrawals.create'))->assertOk()->assertSee('no valid mobile number');
    }

    public function test_an_inactive_member_cannot_request(): void
    {
        $this->member->forceFill(['status' => 'suspended'])->saveQuietly();

        $this->submit()->assertRedirect(route('portal.login'));
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        $this->expectException(ValidationException::class);
        app(WithdrawalRequestService::class)->submit($this->member->fresh(), 100000.0, false, null);
    }

    public function test_requests_cannot_over_commit_the_balance_and_only_one_can_be_open(): void
    {
        $service = app(WithdrawalRequestService::class);
        $this->policy(['daily_limit_per_member' => null]);

        $this->submit(['amount' => 1500000])->assertSessionHasNoErrors();
        $this->assertEquals(500000.0, $service->availableBalance($this->member));

        // A second request is refused while the first is open, with a specific message
        $this->submit(['amount' => 400000])->assertSessionHasErrors('amount');
        $this->assertStringContainsString('already have a withdrawal request', session('errors')->first('amount'));
        $this->assertSame(1, WithdrawalRequest::withoutTenantScope()->count());

        // Cancelling frees both the slot and the reserved money
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $this->openRequest()->id))->assertSessionHasNoErrors();
        $this->assertEquals(2000000.0, $service->availableBalance($this->member));

        $this->submit(['amount' => 2000001])->assertSessionHasErrors('amount');
        $this->assertStringContainsString('at most UGX 2,000,000.00', session('errors')->first('amount'));
    }

    public function test_the_minimum_applies_unless_the_whole_balance_is_withdrawn(): void
    {
        $this->submit(['amount' => 500])->assertSessionHasErrors('amount');

        $poor = $this->makeMember($this->sacco, 600);
        $this->submit(['amount' => 600], $poor)->assertSessionHasNoErrors();
    }

    public function test_block_mode_refuses_while_a_loan_is_outstanding(): void
    {
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 500000]);

        $this->submit()->assertSessionHasErrors('amount');
        $this->assertStringContainsString('cannot be withdrawn while a loan', session('errors')->first('amount'));
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());
    }

    public function test_keep_ratio_mode_allows_only_the_part_above_the_cover(): void
    {
        LendingPolicy::resolveFor($this->sacco->id)->update(['max_loan_to_savings_ratio' => 2.0]);
        $this->policy(['loan_cover_mode' => 'keep_ratio_cover', 'daily_limit_per_member' => null]);
        // Loan 2,000,000 / 2 = 1,000,000 must stay, so up to 1,000,000 can leave
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 2000000]);

        $this->submit(['amount' => 1000001])->assertSessionHasErrors('amount');
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        $this->submit(['amount' => 1000000])->assertSessionHasNoErrors();
        $this->assertSame(1, WithdrawalRequest::withoutTenantScope()->count());
    }

    public function test_early_withdrawal_is_only_offered_when_a_fee_is_set_and_the_fee_is_exact(): void
    {
        $this->submit(['early' => 1])->assertSessionHasErrors('early');
        $this->asMember($this->member)->get(route('portal.withdrawals.create'))->assertOk()->assertDontSee('without waiting for the notice period');

        $this->policy(['early_withdrawal_fee_percent' => 2.5]);
        $this->asMember($this->member)->get(route('portal.withdrawals.create'))->assertOk()->assertSee('without waiting for the notice period')->assertSee('2.5%');

        $this->submit(['early' => 1, 'amount' => 123457])->assertSessionHasNoErrors();
        $request = $this->openRequest();
        $this->assertTrue($request->early);
        $this->assertSame('3086.43', $request->fee_amount);
        $this->assertSame('120370.57', $request->net_amount);
        $this->assertSame(now()->toDateString(), $request->available_from->toDateString());

        // Approve now (no waiting), pay: the ledger records the full amount, the net is what staff hand over
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();
        $this->asStaff($this->officer)->get(route('tenant.withdrawals.show', $request))->assertOk()->assertSee('120,370.57');
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('123457.00', Saving::withoutTenantScope()->where('type', 'withdrawal')->firstOrFail()->amount);
    }

    public function test_the_daily_limit_counts_todays_ledger_withdrawals_and_open_requests(): void
    {
        $this->policy(['daily_limit_per_member' => 150000]);
        Saving::factory()->withdrawal(100000)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'transaction_date' => now()->toDateString()]);

        $this->submit(['amount' => 60000])->assertSessionHasErrors('amount');
        $this->assertStringContainsString('daily withdrawal limit', session('errors')->first('amount'));

        $this->submit(['amount' => 50000])->assertSessionHasNoErrors();

        // Yesterday's withdrawals do not count
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $this->openRequest()->id));
        Saving::withoutTenantScope()->where('type', 'withdrawal')->get()->each(fn () => null);
        $this->policy(['daily_limit_per_member' => 100000]);
        $this->travelTo(now()->addDay());
        $this->submit(['amount' => 100000])->assertSessionHasNoErrors();
    }

    public function test_the_security_hold_follows_a_password_or_phone_change(): void
    {
        $this->member->changePortalPassword('AnotherPass456');
        $this->submit(['password' => 'AnotherPass456'])->assertSessionHasErrors('amount');
        $this->assertStringContainsString('withdrawals are paused', session('errors')->first('amount'));
        $this->assertSame(0, WithdrawalRequest::withoutTenantScope()->count());

        // The hold ends after the configured days
        $this->travelTo(now()->addDays(3)->addMinute());
        $this->submit(['password' => 'AnotherPass456'])->assertSessionHasNoErrors();
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $this->openRequest()->id));

        // A real phone change starts a new hold; switching it off lifts it
        $this->member->fresh()->update(['phone' => '0700555666']);
        $this->submit(['password' => 'AnotherPass456'])->assertSessionHasErrors('amount');
        $this->policy(['security_hold_days' => 0]);
        $this->submit(['password' => 'AnotherPass456'])->assertSessionHasNoErrors();
        $this->assertSame('+256700555666', $this->openRequest()->destination_phone);
    }

    public function test_cancelling_works_while_pending_or_approved_but_not_after_payment(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $first = $this->openRequest();
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $first->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->cancelled_at);

        $approved = $this->approvedRequest();
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $approved->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $approved->fresh()->status);
        $this->assertSame(2, AuditLog::withoutTenantScope()->where('action', 'withdrawal.cancelled')->count());

        $paid = $this->approvedRequest();
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $paid), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $paid->id))->assertSessionHasErrors('request');
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_unpaid_requests_expire_after_the_configured_days(): void
    {
        $this->policy(['notice_days' => 0, 'request_expiry_days' => 10]);
        $this->submit()->assertSessionHasNoErrors();
        $request = $this->openRequest();

        $this->travelTo(now()->addDays(10));
        $this->artisan('withdrawals:expire')->assertSuccessful();
        $this->assertSame('pending', $request->fresh()->status);

        $this->travelTo(now()->addDay());
        $this->artisan('withdrawals:expire')->assertSuccessful();
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->expired_at);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'withdrawal.expired')->count());
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);

        // Running again does nothing, and the member can ask again
        $this->artisan('withdrawals:expire')->assertSuccessful();
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'withdrawal.expired')->count());
        $this->submit()->assertSessionHasNoErrors();
    }

    public function test_requests_above_the_threshold_need_an_administrator(): void
    {
        $this->policy(['notice_days' => 0, 'admin_approval_threshold' => 300000, 'daily_limit_per_member' => null]);
        $this->submit(['amount' => 400000])->assertSessionHasNoErrors();
        $request = $this->openRequest();

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasErrors('request');
        $this->assertSame('pending', $request->fresh()->status);
        $this->asStaff($this->officer)->get(route('tenant.withdrawals.show', $request))->assertOk()->assertSee('A SACCO administrator must approve')->assertDontSee('Approve request');

        $this->asStaff($this->admin)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $request->fresh()->decided_by);

        // After an administrator approved, an officer may record the payout
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $request->fresh()->status);
    }

    public function test_an_officer_can_approve_up_to_the_threshold_but_never_when_it_is_blank(): void
    {
        $this->policy(['notice_days' => 0, 'admin_approval_threshold' => 300000, 'daily_limit_per_member' => null]);
        $this->submit(['amount' => 300000])->assertSessionHasNoErrors();
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.approve', $this->openRequest()))->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->openRequest()->status);

        $this->asMember($this->member)->post(route('portal.withdrawals.cancel', $this->openRequest()->id));
        $this->policy(['admin_approval_threshold' => null]);
        $this->submit(['amount' => 5000])->assertSessionHasNoErrors();
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.approve', $this->openRequest()))->assertSessionHasErrors('request');
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.approve', $this->openRequest()))->assertSessionHasNoErrors();
    }

    public function test_reject_needs_a_reason_and_the_member_sees_it(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $request = $this->openRequest();

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.reject', $request), ['decision_reason' => ''])->assertSessionHasErrors('decision_reason');
        $this->assertSame('pending', $request->fresh()->status);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.reject', $request), ['decision_reason' => 'Please come to the office first.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'withdrawal.rejected')->count());

        $this->asMember($this->member)->get(route('portal.withdrawals.show', $request->id))->assertOk()->assertSee('Please come to the office first.');
    }

    public function test_a_repeated_payout_creates_no_second_ledger_row(): void
    {
        $request = $this->approvedRequest();

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('request');
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('request');

        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'withdrawal.paid')->count());
        $this->assertEquals(1900000.0, $this->member->fresh()->savings_balance);
    }

    public function test_a_stale_copy_of_the_request_cannot_pay_twice_and_the_ledger_link_is_unique(): void
    {
        $request = $this->approvedRequest();
        $service = app(WithdrawalRequestService::class);

        // Two clicks arriving together: the second one locks the row after the first and finds it paid
        $service->recordPayout($this->officer, $request->id, 'cash', null);
        try {
            $service->recordPayout($this->officer, $request->id, 'cash', null);
            $this->fail('A second payout must not be possible.');
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());

        // Even if code tried to link the same ledger row to a second request, the database refuses
        $second = new WithdrawalRequest([
            'sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'reference' => 'WDR-X-1', 'amount' => 1000, 'net_amount' => 1000,
            'destination_phone' => '+256772123456', 'available_from' => now()->toDateString(),
        ]);
        $second->save();
        $this->expectException(QueryException::class);
        $second->forceFill(['saving_id' => $request->fresh()->saving_id])->save();
    }

    public function test_payout_checks_the_money_rules_again_at_payment_time(): void
    {
        // Balance dropped after approval (a counter withdrawal)
        $this->policy(['daily_limit_per_member' => null]);
        $request = $this->approvedRequest(1500000);
        Saving::factory()->withdrawal(700000)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'transaction_date' => now()->subDays(2)->toDateString()]);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNull($request->fresh()->saving_id);
        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_payout_is_refused_when_a_loan_was_disbursed_after_approval(): void
    {
        $request = $this->approvedRequest();
        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 800000]);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_payout_is_refused_when_the_daily_limit_was_used_up_after_approval(): void
    {
        $request = $this->approvedRequest(100000);
        $this->policy(['daily_limit_per_member' => 150000]);
        Saving::factory()->withdrawal(100000)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'transaction_date' => now()->toDateString()]);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_payout_needs_a_reference_unless_it_was_cash(): void
    {
        $request = $this->approvedRequest();

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'mtn_momo'])->assertSessionHasErrors('payout_reference');
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'bitcoin', 'payout_reference' => 'x'])->assertSessionHasErrors('payout_method');
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_only_an_approved_request_can_be_paid_and_a_pending_one_cannot_skip_review(): void
    {
        $this->policy(['notice_days' => 0]);
        $this->submit()->assertSessionHasNoErrors();

        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $this->openRequest()), ['payout_method' => 'cash'])->assertSessionHasErrors('request');
        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_requests_are_never_deleted(): void
    {
        $this->submit()->assertSessionHasNoErrors();

        $this->expectException(RuntimeException::class);
        $this->openRequest()->delete();
    }

    public function test_tenant_isolation_between_saccos_and_between_members(): void
    {
        $other = Sacco::factory()->create(['status' => 'active']);
        $otherAdmin = User::factory()->create(['sacco_id' => $other->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $otherMember = $this->makeMember($other, 900000, '0755123456');

        $this->policy(['notice_days' => 0]);
        $this->submit()->assertSessionHasNoErrors();
        $mine = $this->openRequest();
        $this->submit(['amount' => 50000], $otherMember)->assertSessionHasNoErrors();
        $theirs = $this->openRequest($otherMember);
        $this->assertNotSame($mine->reference, 'x');

        // Staff of the other SACCO see none of this SACCO's requests and cannot act on them
        $this->asStaff($otherAdmin)->get(route('tenant.withdrawals.index'))->assertOk()->assertDontSee($this->member->full_name)->assertSee($otherMember->full_name);
        $this->asStaff($otherAdmin)->get(route('tenant.withdrawals.show', $mine))->assertNotFound();
        $this->asStaff($otherAdmin)->post(route('tenant.withdrawals.approve', $mine))->assertNotFound();
        $this->asStaff($otherAdmin)->post(route('tenant.withdrawals.reject', $mine), ['decision_reason' => 'Nope nope'])->assertNotFound();
        $this->asStaff($otherAdmin)->post(route('tenant.withdrawals.pay', $mine), ['payout_method' => 'cash'])->assertNotFound();
        $this->assertSame('pending', $mine->fresh()->status);

        // The service itself refuses a request outside the staff member's SACCO
        try {
            app(WithdrawalRequestService::class)->approve($otherAdmin, $mine->id);
            $this->fail('Cross-SACCO approval must fail.');
        } catch (ModelNotFoundException) {
            // expected
        }

        // A member sees only their own requests, even inside the same SACCO
        $sibling = $this->makeMember($this->sacco, 300000, '0788123456');
        $this->asMember($sibling)->get(route('portal.withdrawals.index'))->assertOk()->assertDontSee($mine->reference);
        $this->asMember($sibling)->get(route('portal.withdrawals.show', $mine->id))->assertNotFound();
        $this->asMember($sibling)->post(route('portal.withdrawals.cancel', $mine->id))->assertNotFound();
        $this->asMember($otherMember)->get(route('portal.withdrawals.show', $mine->id))->assertNotFound();
        $this->assertSame('pending', $mine->fresh()->status);

        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
        $this->assertTrue(app(AuditTrail::class)->verify($other->id)['ok']);
    }

    public function test_references_are_numbered_per_sacco(): void
    {
        $other = Sacco::factory()->create(['status' => 'active']);
        $otherMember = $this->makeMember($other, 900000, '0755123456');

        $this->submit()->assertSessionHasNoErrors();
        $this->submit([], $otherMember)->assertSessionHasNoErrors();

        $this->assertSame(sprintf('WDR-%s-00001', date('Y')), $this->openRequest()->reference);
        $this->assertSame(sprintf('WDR-%s-00001', date('Y')), $this->openRequest($otherMember)->reference);
    }

    public function test_portal_pages_show_the_rules_and_never_expose_scores_or_other_members(): void
    {
        $this->policy(['notice_days' => 5, 'early_withdrawal_fee_percent' => 3, 'daily_limit_per_member' => 800000, 'security_hold_days' => 2]);
        $stranger = $this->makeMember($this->sacco, 123456, '0788123456');
        Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'closed', 'risk_score' => 77.7, 'decision' => 'reject', 'decision_reason' => 'SECRET-REASON']);

        $page = $this->asMember($this->member)->get(route('portal.withdrawals.create'))
            ->assertOk()
            ->assertSee('5 days notice')
            ->assertSee('3%')
            ->assertSee('UGX 800,000')
            ->assertSee('cannot be withdrawn while you have a loan')
            ->assertSee('paused for 2 days')
            ->assertDontSee('77.7')
            ->assertDontSee('SECRET-REASON')
            ->assertDontSee($stranger->full_name);
        $page->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));

        $this->submit()->assertSessionHasNoErrors();
        $detail = $this->asMember($this->member)->get(route('portal.withdrawals.show', $this->openRequest()->id));
        $detail->assertOk()->assertDontSee($this->admin->name)->assertDontSee($this->officer->name)->assertDontSee('77.7');
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
    }

    public function test_the_pending_count_badge_and_filters_work_for_staff(): void
    {
        $this->policy(['notice_days' => 0]);
        $this->submit()->assertSessionHasNoErrors();
        $request = $this->openRequest();

        $this->asStaff($this->officer)->get(route('tenant.dashboard'))->assertOk()->assertSee('1 waiting for review');
        $this->asStaff($this->officer)->get(route('tenant.withdrawals.index', ['status' => 'pending', 'search' => $this->member->full_name]))->assertOk()->assertSee($request->reference);
        $this->asStaff($this->officer)->get(route('tenant.withdrawals.index', ['status' => 'paid']))->assertOk()->assertDontSee($request->reference);
        $this->asStaff($this->officer)->get(route('tenant.withdrawals.show', $request))->assertOk()->assertSee('Rules applied')->assertSee($this->member->full_name);
    }

    public function test_audit_entries_use_plain_labels(): void
    {
        $request = $this->approvedRequest();
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'airtel_money', 'payout_reference' => 'AM9'])->assertSessionHasNoErrors();

        $page = $this->asStaff($this->admin)->get(route('tenant.audit_log.index'))->assertOk();
        foreach (['Member asked to withdraw savings', 'Withdrawal request approved', 'Withdrawal paid out'] as $label) {
            $page->assertSee($label);
        }

        $paid = AuditLog::withoutTenantScope()->where('action', 'withdrawal.paid')->firstOrFail();
        $this->assertContains('Airtel Money', array_column($paid->displayDetails(), 'value'));
    }

    public function test_the_expiry_command_is_scheduled_daily_at_midday_utc(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'withdrawals:expire'));

        $this->assertCount(1, $events);
        $this->assertSame('10 12 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_no_daily_scheduled_command_runs_while_the_server_is_switched_off(): void
    {
        // The VM is off 19:00-06:00 UTC, so any command pinned to a fixed hour must sit inside 06:00-18:59.
        foreach (app(Schedule::class)->events() as $event) {
            [, $hour] = explode(' ', $event->expression);

            if (ctype_digit($hour)) {
                $this->assertGreaterThanOrEqual(6, (int) $hour, $event->command);
                $this->assertLessThan(19, (int) $hour, $event->command);
            }
        }
    }

    public function test_a_loan_disbursed_through_the_real_path_after_approval_blocks_the_payout(): void
    {
        $request = $this->approvedRequest();
        $loan = Loan::factory()->approved()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'principal' => 800000]);

        $loan->disburse($this->admin, 'cash', null, now()->toDateString());
        $this->assertSame('disbursed', $loan->fresh()->status);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());
    }

    public function test_disbursement_re_reads_the_loan_under_the_lock_so_a_stale_copy_cannot_disburse_twice(): void
    {
        $loan = Loan::factory()->approved()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        $stale = Loan::withoutTenantScope()->findOrFail($loan->id);

        $loan->disburse($this->admin, 'cash', null, now()->toDateString());
        $this->assertSame('approved', $stale->status);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $stale->disburse($this->admin, 'cash', null, now()->toDateString());
        } finally {
            $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'loan.disbursed')->count());
        }
    }

    private function approvedBy(User $approver, float $amount): WithdrawalRequest
    {
        $this->policy(['notice_days' => 0, 'daily_limit_per_member' => null]);
        $this->submit(['amount' => $amount])->assertSessionHasNoErrors();
        $request = $this->openRequest();
        $this->asStaff($approver)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();

        return $request->fresh();
    }

    public function test_the_payer_rule_defaults_to_above_threshold(): void
    {
        $this->assertSame('above_threshold', WithdrawalPolicy::resolveFor($this->sacco->id)->payer_must_differ_from_approver);
    }

    public function test_payer_rule_never_lets_the_approver_pay_any_amount(): void
    {
        $this->policy(['payer_must_differ_from_approver' => 'never']);
        $request = $this->approvedBy($this->admin, 600000);

        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $request->fresh()->status);
    }

    public function test_payer_rule_above_threshold_blocks_the_approver_only_for_large_requests(): void
    {
        $this->policy(['payer_must_differ_from_approver' => 'above_threshold']);

        $small = $this->approvedBy($this->admin, 100000);
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $small), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $small->fresh()->status);

        $large = $this->approvedBy($this->admin, 600000);
        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $large), ['payout_method' => 'cash'])
            ->assertSessionHasErrors('request');
        $this->assertStringContainsString('someone else', session('errors')->first('request'));
        $this->assertSame('approved', $large->fresh()->status);
        $this->assertSame(1, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());

        // The page tells the approver instead of offering the form, and another person can pay
        $this->asStaff($this->admin)->get(route('tenant.withdrawals.show', $large))->assertOk()->assertSee('requires someone else')->assertDontSee('Transaction reference');
        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $large), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $large->fresh()->status);
    }

    public function test_payer_rule_always_blocks_the_approver_for_every_amount(): void
    {
        $this->policy(['payer_must_differ_from_approver' => 'always']);
        $request = $this->approvedBy($this->officer, 50000);

        $this->asStaff($this->officer)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasErrors('request');
        $this->assertSame(0, Saving::withoutTenantScope()->where('type', 'withdrawal')->count());

        $this->asStaff($this->admin)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $request->fresh()->paid_by);
        $this->assertSame($this->officer->id, $request->fresh()->decided_by);
    }

    public function test_a_single_staff_sacco_can_use_never_and_is_stuck_on_the_stricter_values(): void
    {
        $solo = Sacco::factory()->create(['status' => 'active']);
        $soloAdmin = User::factory()->create(['sacco_id' => $solo->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $soloMember = $this->makeMember($solo, 2000000, '0755123456');
        $this->policy(['notice_days' => 0, 'daily_limit_per_member' => null, 'payer_must_differ_from_approver' => 'never'], $solo);

        $this->submit(['amount' => 600000], $soloMember)->assertSessionHasNoErrors();
        $request = $this->openRequest($soloMember);
        $this->asStaff($soloAdmin)->post(route('tenant.withdrawals.approve', $request))->assertSessionHasNoErrors();
        $this->asStaff($soloAdmin)->post(route('tenant.withdrawals.pay', $request), ['payout_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $request->fresh()->status);

        // With the default, the same lone administrator could not pay a large request
        $this->policy(['payer_must_differ_from_approver' => 'above_threshold'], $solo);
        $this->submit(['amount' => 600000], $soloMember)->assertSessionHasNoErrors();
        $second = $this->openRequest($soloMember);
        $this->asStaff($soloAdmin)->post(route('tenant.withdrawals.approve', $second))->assertSessionHasNoErrors();
        $this->asStaff($soloAdmin)->post(route('tenant.withdrawals.pay', $second), ['payout_method' => 'cash'])->assertSessionHasErrors('request');
    }

    public function test_the_payer_rule_is_validated_explained_and_audited(): void
    {
        $this->asStaff($this->admin)->get(route('tenant.withdrawal_policy.edit'))->assertOk()
            ->assertSee('Approving and paying out')
            ->assertSee('one person cannot approve and pay alone');

        $base = ['notice_days' => 7, 'loan_cover_mode' => 'block_while_loan_active', 'early_withdrawal_fee_percent' => 0, 'daily_limit_per_member' => 1000000,
            'admin_approval_threshold' => 500000, 'security_hold_days' => 3, 'request_expiry_days' => 30];

        $this->asStaff($this->admin)->put(route('tenant.withdrawal_policy.update'), $base + ['payer_must_differ_from_approver' => 'sometimes'])
            ->assertSessionHasErrors('payer_must_differ_from_approver');

        $this->asStaff($this->admin)->put(route('tenant.withdrawal_policy.update'), $base + ['payer_must_differ_from_approver' => 'always'])->assertSessionHasNoErrors();
        $log = AuditLog::withoutTenantScope()->where('action', 'withdrawal_policy.updated')->latest('id')->firstOrFail();
        $this->assertSame(['above_threshold', 'always'], $log->detailsArray()['changes']['payer_must_differ_from_approver']);
        $this->assertContains('Who may pay out a request they approved', array_column($log->displayDetails(), 'label'));
        $this->assertContains('For large requests: a different person must pay to Always: a different person must pay', array_column($log->displayDetails(), 'value'));
    }
}
