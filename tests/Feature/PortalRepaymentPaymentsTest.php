<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\SaccoPaymentSetting;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalRepaymentPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'repayment-test-hash';

    private Sacco $sacco;

    private User $admin;

    private Member $member;

    private Loan $loan;

    /** @var array<string, mixed>|null */
    private ?array $verifyData = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->member = $this->portalMember($this->sacco, $this->admin);
        $this->loan = $this->activeLoan($this->member, 100000);

        SaccoPaymentSetting::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id, 'provider' => 'flutterwave', 'environment' => 'sandbox',
            'credentials' => ['secret_key' => 'FLWSECK_TEST-secretvalue'], 'webhook_secret' => self::HASH, 'enabled' => true,
        ]);

        Http::fake([
            '*/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/rep']]),
            '*/v3/transactions/*' => fn () => $this->verifyData === null
                ? Http::response(['status' => 'error', 'message' => 'No transaction was found', 'data' => null], 404)
                : Http::response(['status' => 'success', 'data' => $this->verifyData]),
        ]);
    }

    private function portalMember(Sacco $sacco, User $staff): Member
    {
        $member = Member::factory()->consented($staff)->create(['sacco_id' => $sacco->id, 'status' => 'active', 'phone' => '0772123456']);
        $member->issuePortalCredentials();
        $member->forceFill(['portal_must_change_password' => false])->save();

        return $member;
    }

    private function activeLoan(Member $member, float $total): Loan
    {
        return Loan::factory()->disbursed()->create([
            'sacco_id' => $member->sacco_id, 'member_id' => $member->id,
            'principal' => $total, 'interest_rate' => 0, 'total_interest' => 0, 'total_amount' => $total,
        ]);
    }

    private function signInAsMember(Member $member): static
    {
        $this->actingAs($member->fresh(), 'member');
        Auth::shouldUse('web');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startRepayment(array $input = [], ?Loan $loan = null): PaymentTransaction
    {
        $loan ??= $this->loan;

        $this->signInAsMember($this->member)
            ->post(route('portal.repayments.store', $loan->id), array_merge(['amount' => 30000, 'network' => 'mtn', 'phone' => '0772123456'], $input))
            ->assertSessionHasNoErrors();

        return PaymentTransaction::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function providerConfirms(PaymentTransaction $payment, array $overrides = []): void
    {
        $this->verifyData = array_merge([
            'id' => 777, 'tx_ref' => $payment->tx_ref, 'flw_ref' => 'FLW-REP-'.$payment->id, 'status' => 'successful',
            'amount' => (float) $payment->amount, 'currency' => 'UGX', 'app_fee' => 0,
        ], $overrides);
    }

    private function webhook(PaymentTransaction $payment)
    {
        return $this->postJson(route('webhooks.flutterwave', $this->sacco), ['event' => 'charge.completed', 'data' => ['id' => 777, 'tx_ref' => $payment->tx_ref]], ['verif-hash' => self::HASH]);
    }

    private function repayments(): int
    {
        return Repayment::withoutTenantScope()->count();
    }

    public function test_the_loan_page_offers_pay_now_only_for_loans_that_can_take_repayments(): void
    {
        $closed = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'closed']);
        $pending = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'pending']);

        $html = $this->signInAsMember($this->member)->get(route('portal.loans'))->assertOk()->getContent();

        $this->assertStringContainsString(route('portal.repayments.create', $this->loan->id), $html);
        $this->assertStringNotContainsString(route('portal.repayments.create', $closed->id), $html);
        $this->assertStringNotContainsString(route('portal.repayments.create', $pending->id), $html);

        SaccoPaymentSetting::withoutTenantScope()->update(['enabled' => false]);
        $this->get(route('portal.loans'))->assertDontSee('Pay now');
    }

    public function test_starting_a_repayment_creates_a_pending_payment_for_that_loan_only(): void
    {
        $this->signInAsMember($this->member)->get(route('portal.repayments.create', $this->loan->id))->assertOk()->assertSee('Secured by Flutterwave');

        $this->post(route('portal.repayments.store', $this->loan->id), ['amount' => 30000, 'network' => 'airtel', 'phone' => '0752123456'])
            ->assertRedirect('https://checkout.flutterwave.test/pay/rep');

        $payment = PaymentTransaction::withoutTenantScope()->firstOrFail();
        $this->assertSame('repayment', $payment->purpose);
        $this->assertSame($this->loan->id, $payment->loan_id);
        $this->assertSame($this->member->id, $payment->member_id);
        $this->assertEquals(30000.0, (float) $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, $this->repayments());
    }

    public function test_the_browser_cannot_pick_another_loan_member_or_amount_for_a_full_payment(): void
    {
        $other = $this->portalMember($this->sacco, $this->admin);
        $othersLoan = $this->activeLoan($other, 50000);

        $payment = $this->startRepayment(['loan_id' => $othersLoan->id, 'member_id' => $other->id, 'sacco_id' => 99, 'pay_full' => 1, 'amount' => 5]);

        $this->assertSame($this->loan->id, $payment->loan_id);
        $this->assertSame($this->member->id, $payment->member_id);
        $this->assertSame($this->sacco->id, $payment->sacco_id);
        $this->assertEquals(100000.0, (float) $payment->amount, 'Pay-in-full uses the balance the server calculates');
    }

    public function test_a_confirmed_payment_records_exactly_one_repayment_through_the_ledger(): void
    {
        $payment = $this->startRepayment();
        $this->providerConfirms($payment);

        $this->webhook($payment)->assertOk();
        $this->webhook($payment)->assertOk(); // replay

        $this->assertSame(1, $this->repayments());
        $repayment = Repayment::withoutTenantScope()->firstOrFail();
        $this->assertSame($this->loan->id, $repayment->loan_id);
        $this->assertSame($this->member->id, $repayment->member_id);
        $this->assertEquals(30000.0, (float) $repayment->amount);
        $this->assertSame('mtn_momo', $repayment->payment_method);
        $this->assertSame('FLW-REP-'.$payment->id, $repayment->payment_reference);
        $this->assertStringStartsWith('REP-', $repayment->repayment_reference);

        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame($repayment->id, $payment->repayment_id);
        $this->assertNull($payment->saving_id);

        $this->assertEquals(70000.0, $this->loan->fresh()->remaining_balance);
        $this->assertSame('disbursed', $this->loan->fresh()->status);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.succeeded')->count());
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_paying_the_full_balance_closes_the_loan(): void
    {
        $payment = $this->startRepayment(['pay_full' => 1, 'amount' => null]);
        $this->providerConfirms($payment);

        $this->webhook($payment)->assertOk();

        $this->assertSame('closed', $this->loan->fresh()->status);
        $this->assertEquals(0.0, $this->loan->fresh()->remaining_balance);

        // Nothing more can be paid on it
        $this->signInAsMember($this->member)->post(route('portal.repayments.store', $this->loan->id), ['amount' => 1000, 'network' => 'mtn', 'phone' => '0772123456'])
            ->assertSessionHasErrors('amount');
        $this->get(route('portal.repayments.create', $this->loan->id))->assertRedirect(route('portal.loans'));
    }

    public function test_overpayment_and_tiny_partial_payments_are_blocked_with_friendly_messages(): void
    {
        $this->signInAsMember($this->member);
        $base = ['network' => 'mtn', 'phone' => '0772123456'];

        $this->post(route('portal.repayments.store', $this->loan->id), $base + ['amount' => 100001])
            ->assertSessionHasErrors(['amount' => 'You cannot pay more than the balance owed, UGX 100,000.00.']);
        $this->post(route('portal.repayments.store', $this->loan->id), $base + ['amount' => 999])
            ->assertSessionHasErrors('amount');
        $this->post(route('portal.repayments.store', $this->loan->id), $base)->assertSessionHasErrors('amount');
        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->count());

        // A small final balance may be paid exactly, even below the minimum
        $small = $this->activeLoan($this->member, 600);
        $this->post(route('portal.repayments.store', $small->id), $base + ['amount' => 600])->assertSessionHasNoErrors();
        $this->post(route('portal.repayments.store', $small->id), $base + ['amount' => 500])->assertSessionHasErrors('amount');
    }

    public function test_closed_pending_and_foreign_loans_cannot_be_paid(): void
    {
        $closed = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'closed']);
        $pending = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'pending']);
        $other = $this->portalMember($this->sacco, $this->admin);
        $othersLoan = $this->activeLoan($other, 50000);
        $saccoB = Sacco::factory()->create(['status' => 'active']);
        $staffB = User::factory()->create(['sacco_id' => $saccoB->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $loanB = $this->activeLoan($this->portalMember($saccoB, $staffB), 50000);

        $this->signInAsMember($this->member);
        $payload = ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'];

        foreach ([$closed, $pending] as $loan) {
            $this->post(route('portal.repayments.store', $loan->id), $payload)->assertSessionHasErrors('amount');
        }
        foreach ([$othersLoan, $loanB] as $loan) {
            $this->post(route('portal.repayments.store', $loan->id), $payload)->assertNotFound();
            $this->get(route('portal.repayments.create', $loan->id))->assertNotFound();
        }
        $this->post(route('portal.repayments.store', 999999), $payload)->assertNotFound();

        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->count());
    }

    public function test_wrong_amounts_failures_and_bad_signatures_record_nothing(): void
    {
        $payment = $this->startRepayment();

        $this->providerConfirms($payment, ['amount' => 29999.0]);
        $this->webhook($payment)->assertOk();
        $this->providerConfirms($payment, ['currency' => 'USD']);
        $this->webhook($payment)->assertOk();
        $this->assertSame(0, $this->repayments());

        $this->providerConfirms($payment);
        $this->postJson(route('webhooks.flutterwave', $this->sacco), ['event' => 'charge.completed', 'data' => ['tx_ref' => $payment->tx_ref]], ['verif-hash' => 'nope'])->assertUnauthorized();
        $this->assertSame(0, $this->repayments());

        $this->providerConfirms($payment, ['status' => 'failed', 'processor_response' => 'Declined']);
        $this->webhook($payment)->assertOk();
        $this->assertSame(0, $this->repayments());
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertEquals(100000.0, $this->loan->fresh()->remaining_balance);
    }

    public function test_when_two_payments_would_overpay_the_ledger_refuses_the_second_and_staff_are_told(): void
    {
        $first = $this->startRepayment(['amount' => 80000]);
        $second = $this->startRepayment(['amount' => 80000, 'network' => 'airtel']);
        $this->assertNotSame($first->id, $second->id);

        $this->providerConfirms($first);
        $this->webhook($first)->assertOk();
        $this->providerConfirms($second);
        $this->webhook($second)->assertOk();

        $this->assertSame(1, $this->repayments());
        $this->assertEquals(20000.0, $this->loan->fresh()->remaining_balance);
        $second->refresh();
        $this->assertSame('failed', $second->status);
        $this->assertStringStartsWith('Payment received but not recorded', $second->failure_reason);
        $this->assertNull($second->repayment_id);
    }

    public function test_reconcile_recovers_a_missed_repayment_webhook(): void
    {
        $payment = $this->startRepayment();
        $this->providerConfirms($payment);

        $this->travel(5)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(1, $this->repayments());
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_the_status_page_for_a_repayment_links_back_to_loans_and_staff_see_the_loan(): void
    {
        $payment = $this->startRepayment();
        $this->providerConfirms($payment);

        $this->signInAsMember($this->member)->get(route('portal.payments.show', $payment))
            ->assertOk()->assertSee('Payment received')->assertSee(route('portal.loans'));

        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');
        $this->get(route('tenant.payments.index', ['purpose' => 'repayment']))->assertOk()
            ->assertSee($payment->tx_ref)->assertSee($this->loan->loan_reference);
    }

    public function test_the_provider_receives_the_exact_server_amount(): void
    {
        $this->startRepayment(['amount' => 30000]);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/payments') && $r['amount'] === '30000' && $r['currency'] === 'UGX');
    }
}
