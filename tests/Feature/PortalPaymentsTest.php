<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Sacco;
use App\Models\SaccoPaymentSetting;
use App\Models\Saving;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Payments\FakeGateway;
use App\Support\UgandaPhone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const HASH_A = 'hash-for-sacco-a';

    private const HASH_B = 'hash-for-sacco-b';

    private Sacco $sacco;

    private User $admin;

    private Member $member;

    /** @var array<string, mixed>|null What the fake provider's verify API answers with */
    private ?array $verifyData = null;

    private bool $startFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->member = $this->portalMember($this->sacco, $this->admin);
        $this->enablePayments($this->sacco, self::HASH_A);

        Http::fake([
            '*/v3/payments' => fn () => $this->startFails
                ? Http::response(['status' => 'error', 'message' => 'Service unavailable'], 503)
                : Http::response(['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/abc']]),
            '*/v3/transactions/*' => function (HttpRequest $request) {
                return $this->verifyData === null
                    ? Http::response(['status' => 'error', 'message' => 'No transaction was found for this id', 'data' => null], 404)
                    : Http::response(['status' => 'success', 'message' => 'Transaction fetched successfully', 'data' => $this->verifyData]);
            },
        ]);
    }

    private function portalMember(Sacco $sacco, User $staff): Member
    {
        $member = Member::factory()->consented($staff)->create(['sacco_id' => $sacco->id, 'status' => 'active', 'phone' => '0772123456']);
        $member->issuePortalCredentials();
        $member->forceFill(['portal_must_change_password' => false])->save();

        return $member;
    }

    private function enablePayments(Sacco $sacco, string $hash, bool $enabled = true): SaccoPaymentSetting
    {
        return SaccoPaymentSetting::withoutTenantScope()->create([
            'sacco_id' => $sacco->id,
            'provider' => 'flutterwave',
            'environment' => 'sandbox',
            'credentials' => ['public_key' => 'FLWPUBK_TEST-x', 'secret_key' => 'FLWSECK_TEST-secretvalue', 'encryption_key' => 'FLWSECK_TEST-enc'],
            'webhook_secret' => $hash,
            'enabled' => $enabled,
        ]);
    }

    private function signInAsMember(Member $member): static
    {
        $this->actingAs($member->fresh(), 'member');
        Auth::shouldUse('web');

        return $this;
    }

    /**
     * Start a deposit as the member and return the payment row it created.
     *
     * @param  array<string, mixed>  $input
     */
    private function startDeposit(array $input = []): PaymentTransaction
    {
        $this->signInAsMember($this->member)->post(route('portal.deposits.store'), array_merge([
            'amount' => 5000, 'network' => 'mtn', 'phone' => '0772 123 456',
        ], $input))->assertSessionHasNoErrors();

        return PaymentTransaction::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function providerSays(PaymentTransaction $payment, array $overrides = []): void
    {
        $this->verifyData = array_merge([
            'id' => 285959875,
            'tx_ref' => $payment->tx_ref,
            'flw_ref' => 'FLW-MOCK-123',
            'status' => 'successful',
            'amount' => (float) $payment->amount,
            'currency' => 'UGX',
            'app_fee' => 100,
            'processor_response' => 'Approved',
            'customer' => ['email' => 'secret-person@example.com'],
            'card' => ['last_4digits' => '4242'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookBody(PaymentTransaction $payment, array $data = []): array
    {
        return ['event' => 'charge.completed', 'data' => array_merge(['id' => 285959875, 'tx_ref' => $payment->tx_ref, 'status' => 'successful', 'amount' => 1, 'currency' => 'USD'], $data)];
    }

    private function webhook(Sacco $sacco, array $body, ?string $hash = self::HASH_A)
    {
        return $this->postJson(route('webhooks.flutterwave', $sacco), $body, $hash === null ? [] : ['verif-hash' => $hash]);
    }

    private function savings(): int
    {
        return Saving::withoutTenantScope()->count();
    }

    public function test_starting_a_deposit_records_a_pending_payment_and_sends_the_member_to_the_provider(): void
    {
        $this->signInAsMember($this->member)->get(route('portal.deposits.create'))->assertOk()->assertSee('Secured by Flutterwave');

        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'airtel', 'phone' => '0752 123 456'])
            ->assertRedirect('https://checkout.flutterwave.test/pay/abc');

        $payment = PaymentTransaction::withoutTenantScope()->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('deposit', $payment->purpose);
        $this->assertSame($this->sacco->id, $payment->sacco_id);
        $this->assertSame($this->member->id, $payment->member_id);
        $this->assertSame('+256752123456', $payment->payer_phone);
        $this->assertSame('airtel', $payment->network);
        $this->assertEquals(5000.0, (float) $payment->amount);
        $this->assertSame('UGX', $payment->currency);
        $this->assertStringStartsWith('PAY-'.$this->sacco->id.'-', $payment->tx_ref);
        $this->assertSame(0, $this->savings(), 'Nothing reaches the ledger until the provider confirms');
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.initiated')->count());

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/payments')
            && $r['tx_ref'] === $payment->tx_ref
            && $r['amount'] === '5000'
            && $r['currency'] === 'UGX'
            && $r['payment_options'] === 'mobilemoneyuganda'
            && $r->hasHeader('Authorization'));
    }

    public function test_the_browser_cannot_choose_the_member_sacco_status_or_currency(): void
    {
        $other = Sacco::factory()->create(['status' => 'active']);
        $otherMember = Member::factory()->create(['sacco_id' => $other->id, 'status' => 'active']);

        $payment = $this->startDeposit(['member_id' => $otherMember->id, 'sacco_id' => $other->id, 'status' => 'successful', 'currency' => 'USD', 'tx_ref' => 'MINE', 'fee' => 0, 'saving_id' => 1]);

        $this->assertSame($this->member->id, $payment->member_id);
        $this->assertSame($this->sacco->id, $payment->sacco_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('UGX', $payment->currency);
        $this->assertNotSame('MINE', $payment->tx_ref);
        $this->assertNull($payment->saving_id);
        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->where('member_id', $otherMember->id)->count());
    }

    public function test_amount_network_and_phone_are_validated(): void
    {
        $this->signInAsMember($this->member);

        $this->post(route('portal.deposits.store'), ['amount' => 999, 'network' => 'mtn', 'phone' => '0772123456'])->assertSessionHasErrors('amount');
        $this->post(route('portal.deposits.store'), ['amount' => 5000.5, 'network' => 'mtn', 'phone' => '0772123456'])->assertSessionHasErrors('amount');
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'bitcoin', 'phone' => '0772123456'])->assertSessionHasErrors('network');
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '12345'])->assertSessionHasErrors('phone');
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn'])->assertSessionHasErrors('phone');
        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->count());

        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'card'])->assertSessionHasNoErrors();
        $this->assertSame('card', PaymentTransaction::withoutTenantScope()->firstOrFail()->network);
    }

    public function test_phone_numbers_are_normalised_to_plus_256(): void
    {
        foreach (['0772123456', '772123456', '256772123456', '+256 772 123 456', '(0772) 123-456'] as $input) {
            $this->assertSame('+256772123456', UgandaPhone::normalise($input), $input);
        }
        foreach (['', '12345', '0172123456', '+254772123456', null] as $input) {
            $this->assertNull(UgandaPhone::normalise($input), (string) $input);
        }
    }

    public function test_a_double_click_starts_only_one_payment(): void
    {
        $this->signInAsMember($this->member);
        $payload = ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'];

        $this->post(route('portal.deposits.store'), $payload)->assertRedirect('https://checkout.flutterwave.test/pay/abc');
        $this->post(route('portal.deposits.store'), $payload)->assertRedirect('https://checkout.flutterwave.test/pay/abc');

        $this->assertSame(1, PaymentTransaction::withoutTenantScope()->count());
        Http::assertSentCount(1);
    }

    public function test_starting_payments_is_rate_limited_per_member(): void
    {
        $this->signInAsMember($this->member);

        for ($i = 1; $i <= 6; $i++) {
            $this->post(route('portal.deposits.store'), ['amount' => 1000 + $i, 'network' => 'mtn', 'phone' => '0772123456'])->assertRedirect();
        }

        $this->post(route('portal.deposits.store'), ['amount' => 9000, 'network' => 'mtn', 'phone' => '0772123456'])->assertStatus(429);
    }

    public function test_a_valid_webhook_records_exactly_one_deposit(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);

        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();

        $this->assertSame(1, $this->savings());
        $saving = Saving::withoutTenantScope()->firstOrFail();
        $this->assertSame('deposit', $saving->type);
        $this->assertEquals(5000.0, (float) $saving->amount);
        $this->assertSame('mtn_momo', $saving->payment_method);
        $this->assertSame('FLW-MOCK-123', $saving->payment_reference);
        $this->assertSame($this->member->id, $saving->member_id);
        $this->assertSame($this->sacco->id, $saving->sacco_id);
        $this->assertStringStartsWith('SAV-', $saving->transaction_reference);

        $payment->refresh();
        $this->assertSame('successful', $payment->status);
        $this->assertSame($saving->id, $payment->saving_id);
        $this->assertSame('FLW-MOCK-123', $payment->provider_reference);
        $this->assertEquals(100.0, (float) $payment->fee);
        $this->assertNotNull($payment->completed_at);
        // Only whitelisted provider fields are kept: no customer or card details
        $this->assertSame('successful', $payment->provider_response['status']);
        $this->assertArrayNotHasKey('customer', $payment->provider_response);
        $this->assertArrayNotHasKey('card', $payment->provider_response);

        $this->assertEquals(5000.0, $this->member->fresh()->savings_balance);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.succeeded')->count());
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_the_webhook_body_is_never_trusted_for_the_amount(): void
    {
        $payment = $this->startDeposit(['amount' => 5000]);
        $this->providerSays($payment); // the provider confirms the real 5,000

        // The body claims 900,000 USD; only the verified, matching result is used
        $this->webhook($this->sacco, $this->webhookBody($payment, ['amount' => 900000, 'currency' => 'USD']))->assertOk();

        $this->assertEquals(5000.0, (float) Saving::withoutTenantScope()->firstOrFail()->amount);
    }

    public function test_a_replayed_webhook_creates_no_second_ledger_row(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);

        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();
        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();
        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();

        $this->assertSame(1, $this->savings());
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.succeeded')->count());
        $this->assertEquals(5000.0, $this->member->fresh()->savings_balance);
    }

    public function test_the_database_itself_refuses_two_payments_pointing_at_one_ledger_row(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);
        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();
        $saving = Saving::withoutTenantScope()->firstOrFail();

        $second = $this->startDeposit(['amount' => 6000]);

        $this->expectException(QueryException::class);
        DB::table('payment_transactions')->where('id', $second->id)->update(['saving_id' => $saving->id]);
    }

    public function test_a_bad_or_missing_signature_is_rejected_and_nothing_is_verified_or_recorded(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);

        $this->webhook($this->sacco, $this->webhookBody($payment), 'wrong-hash')->assertUnauthorized();
        $this->webhook($this->sacco, $this->webhookBody($payment), null)->assertUnauthorized();
        $this->webhook($this->sacco, $this->webhookBody($payment), '')->assertUnauthorized();

        $this->assertSame(0, $this->savings());
        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/transactions/'));
    }

    public function test_a_confirmation_with_the_wrong_amount_currency_or_reference_is_ignored(): void
    {
        $payment = $this->startDeposit(['amount' => 5000]);

        foreach ([['amount' => 4999.0], ['amount' => 5001.0], ['currency' => 'USD'], ['tx_ref' => 'SOMEONE-ELSES']] as $overrides) {
            $this->providerSays($payment, $overrides);
            $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();

            $this->assertSame(0, $this->savings(), json_encode($overrides));
            $this->assertSame('pending', $payment->fresh()->status);
        }
    }

    public function test_an_unknown_reference_or_unsupported_event_is_ignored(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);

        $this->webhook($this->sacco, ['event' => 'charge.completed', 'data' => ['id' => 1, 'tx_ref' => 'PAY-NOT-OURS']])->assertOk();
        $this->webhook($this->sacco, ['event' => 'transfer.completed', 'data' => ['tx_ref' => $payment->tx_ref]])->assertOk();
        $this->webhook($this->sacco, [])->assertOk();

        $this->assertSame(0, $this->savings());
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_failed_payment_creates_no_ledger_row(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment, ['status' => 'failed', 'processor_response' => 'Insufficient funds']);

        $this->webhook($this->sacco, $this->webhookBody($payment, ['status' => 'failed']))->assertOk();

        $this->assertSame(0, $this->savings());
        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('Insufficient funds', $payment->failure_reason);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.failed')->count());

        // A repeated failure notice does not repeat the audit entry
        $this->webhook($this->sacco, $this->webhookBody($payment, ['status' => 'failed']))->assertOk();
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.failed')->count());
    }

    public function test_one_saccos_webhook_cannot_touch_another_saccos_payment(): void
    {
        $saccoB = Sacco::factory()->create(['status' => 'active']);
        $adminB = User::factory()->create(['sacco_id' => $saccoB->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->enablePayments($saccoB, self::HASH_B);

        $paymentA = $this->startDeposit();
        $this->providerSays($paymentA);

        // SACCO B's own endpoint and secret, but A's payment reference
        $this->webhook($saccoB, $this->webhookBody($paymentA), self::HASH_B)->assertOk();
        $this->assertSame(0, $this->savings());
        $this->assertSame('pending', $paymentA->fresh()->status);

        // A's secret on B's endpoint, and B's secret on A's endpoint, are both refused
        $this->webhook($saccoB, $this->webhookBody($paymentA), self::HASH_A)->assertUnauthorized();
        $this->webhook($this->sacco, $this->webhookBody($paymentA), self::HASH_B)->assertUnauthorized();
        $this->assertSame(0, $this->savings());

        // The right endpoint still works
        $this->webhook($this->sacco, $this->webhookBody($paymentA))->assertOk();
        $this->assertSame(1, $this->savings());
        $this->assertSame(0, Saving::withoutTenantScope()->where('sacco_id', $saccoB->id)->count());
        $this->assertNotNull($adminB);
    }

    public function test_a_webhook_for_a_sacco_without_payments_is_not_found(): void
    {
        $bare = Sacco::factory()->create(['status' => 'active']);
        $this->webhook($bare, ['event' => 'charge.completed', 'data' => ['tx_ref' => 'x']])->assertNotFound();

        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->update(['enabled' => false]);
        $this->webhook($this->sacco, ['event' => 'charge.completed', 'data' => ['tx_ref' => 'x']])->assertNotFound();
    }

    public function test_reconcile_recovers_a_missed_webhook(): void
    {
        $payment = $this->startDeposit();
        $this->providerSays($payment);

        // Too fresh: the webhook may still arrive, so reconcile leaves it alone
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(0, $this->savings());

        $this->travel(5)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(1, $this->savings());
        $this->assertSame('successful', $payment->fresh()->status);

        // Running it again changes nothing
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(1, $this->savings());
    }

    public function test_reconcile_expires_stale_payments_and_a_late_confirmation_is_still_honoured(): void
    {
        $payment = $this->startDeposit();
        $this->verifyData = null;

        $this->travel(3)->hours();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertSame(1, AuditLog::withoutTenantScope()->where('action', 'payment.expired')->count());
        $this->assertSame(0, $this->savings());

        // The member really paid after the page timed out: the money is recorded, not lost
        $this->providerSays($payment);
        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();
        $this->assertSame(1, $this->savings());
        $this->assertSame('successful', $payment->fresh()->status);
    }

    public function test_a_payment_the_ledger_refuses_is_flagged_for_staff_not_lost_silently(): void
    {
        $payment = $this->startDeposit();
        $this->member->forceFill(['status' => 'inactive'])->save();
        $this->providerSays($payment);

        $this->webhook($this->sacco, $this->webhookBody($payment))->assertOk();

        $this->assertSame(0, $this->savings());
        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertStringStartsWith('Payment received but not recorded', $payment->failure_reason);
    }

    public function test_the_status_page_confirms_a_payment_through_the_provider_and_reports_progress(): void
    {
        $payment = $this->startDeposit();
        $this->signInAsMember($this->member);

        $this->getJson(route('portal.payments.status', $payment))->assertOk()
            ->assertJson(['status' => 'pending', 'final' => false])
            ->assertJsonMissing(['tx_ref' => $payment->tx_ref]);

        $this->providerSays($payment);
        $this->travel(10)->seconds(); // past the per-payment check interval

        $this->getJson(route('portal.payments.status', $payment))->assertOk()->assertJson(['status' => 'successful', 'final' => true]);
        $this->get(route('portal.payments.show', $payment))->assertOk()->assertSee('Payment received')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, $this->savings());
    }

    public function test_a_member_cannot_see_another_members_payment(): void
    {
        $payment = $this->startDeposit();

        $other = $this->portalMember($this->sacco, $this->admin);
        $this->signInAsMember($other);
        $this->get(route('portal.payments.show', $payment))->assertNotFound();
        $this->getJson(route('portal.payments.status', $payment))->assertNotFound();

        $saccoB = Sacco::factory()->create(['status' => 'active']);
        $outsider = $this->portalMember($saccoB, User::factory()->create(['sacco_id' => $saccoB->id, 'role' => 'sacco_admin', 'status' => 'active']));
        $this->signInAsMember($outsider);
        $this->get(route('portal.payments.show', $payment))->assertNotFound();
    }

    public function test_payment_pages_need_a_portal_sign_in(): void
    {
        $this->get(route('portal.deposits.create'))->assertRedirect(route('portal.login'));
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'])->assertRedirect(route('portal.login'));
        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->count());
    }

    public function test_without_settings_or_when_disabled_there_are_no_pay_buttons_and_no_payments(): void
    {
        $this->signInAsMember($this->member)->get(route('portal.savings'))->assertOk()->assertSee('Add savings');

        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->update(['enabled' => false]);
        $this->get(route('portal.savings'))->assertOk()->assertDontSee('Add savings');
        $this->get(route('portal.deposits.create'))->assertRedirect(route('portal.savings'));
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'])->assertRedirect(route('portal.savings'));
        $this->assertSame(0, PaymentTransaction::withoutTenantScope()->count());

        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->delete();
        $this->get(route('portal.savings'))->assertDontSee('Add savings');
    }

    public function test_a_provider_outage_when_starting_leaves_a_failed_record_and_a_friendly_message(): void
    {
        $this->startFails = true;

        $this->signInAsMember($this->member)->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'])
            ->assertSessionHas('error');

        $payment = PaymentTransaction::withoutTenantScope()->firstOrFail();
        $this->assertSame('failed', $payment->status);
        $this->assertSame(0, $this->savings());
    }

    public function test_staff_see_only_their_own_saccos_payments_and_cannot_change_them(): void
    {
        $payment = $this->startDeposit();
        $saccoB = Sacco::factory()->create(['status' => 'active']);
        $staffB = User::factory()->create(['sacco_id' => $saccoB->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->enablePayments($saccoB, self::HASH_B);
        $memberB = $this->portalMember($saccoB, $staffB);
        $paymentB = PaymentTransaction::withoutTenantScope()->create([
            'sacco_id' => $saccoB->id, 'member_id' => $memberB->id, 'purpose' => 'deposit', 'tx_ref' => 'PAY-B-ONLY', 'provider' => 'flutterwave',
            'amount' => 7000, 'currency' => 'UGX', 'network' => 'mtn', 'status' => 'pending',
        ]);

        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');
        $this->get(route('tenant.payments.index'))->assertOk()->assertSee($payment->tx_ref)->assertDontSee($paymentB->tx_ref);
        $this->get(route('tenant.payments.index', ['status' => 'successful']))->assertOk()->assertDontSee($payment->tx_ref);
        $this->get(route('tenant.payments.index', ['search' => $this->member->member_number]))->assertOk()->assertSee($payment->tx_ref);

        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'tenant.payments.'))
            ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== []);
        $this->assertCount(0, $writes);
    }

    public function test_payment_records_can_never_be_deleted(): void
    {
        $payment = $this->startDeposit();

        $this->expectException(\RuntimeException::class);
        $payment->delete();
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden_from_serialisation(): void
    {
        $settings = SaccoPaymentSetting::withoutTenantScope()->firstOrFail();

        $raw = DB::table('sacco_payment_settings')->first();
        $this->assertStringNotContainsString('FLWSECK_TEST-secretvalue', (string) $raw->credentials);
        $this->assertStringNotContainsString(self::HASH_A, (string) $raw->webhook_secret);

        $json = json_encode($settings);
        $this->assertStringNotContainsString('FLWSECK', $json);
        $this->assertStringNotContainsString(self::HASH_A, $json);
        $this->assertSame('FLWSECK_TEST-secretvalue', $settings->credentials['secret_key']);
    }

    public function test_the_gateway_refuses_live_mode_and_non_test_keys_in_the_sandbox(): void
    {
        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->update(['environment' => 'live']);
        $this->signInAsMember($this->member)->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'])->assertSessionHas('error');

        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->firstOrFail()
            ->update(['environment' => 'sandbox', 'credentials' => ['secret_key' => 'FLWSECK-live-looking']]);
        $this->post(route('portal.deposits.store'), ['amount' => 5000, 'network' => 'mtn', 'phone' => '0772123456'])->assertSessionHas('error');

        $this->assertSame(0, $this->savings());
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/payments'));
    }

    public function test_configure_command_stores_encrypted_settings_and_prints_the_webhook_url_without_secrets(): void
    {
        $saccoB = Sacco::factory()->create(['name' => 'Kampala Teachers SACCO', 'status' => 'active']);
        putenv('FLW_V3_PUBLIC_KEY=FLWPUBK_TEST-pub');
        putenv('FLW_V3_SECRET_KEY=FLWSECK_TEST-cmdsecret');
        putenv('FLW_V3_ENCRYPTION_KEY=FLWSECK_TEST-enc');
        putenv('FLW_V3_WEBHOOK_HASH=cmd-hash-value');

        try {
            $this->artisan('payments:configure', ['sacco_id' => $saccoB->id])
                ->expectsOutputToContain('/webhooks/flutterwave/'.$saccoB->id)
                ->doesntExpectOutputToContain('cmdsecret')
                ->doesntExpectOutputToContain('cmd-hash-value')
                ->assertSuccessful();
        } finally {
            foreach (['FLW_V3_PUBLIC_KEY', 'FLW_V3_SECRET_KEY', 'FLW_V3_ENCRYPTION_KEY', 'FLW_V3_WEBHOOK_HASH'] as $name) {
                putenv($name);
            }
        }

        $settings = SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $saccoB->id)->firstOrFail();
        $this->assertTrue($settings->enabled);
        $this->assertSame('sandbox', $settings->environment);
        $this->assertSame('FLWSECK_TEST-cmdsecret', $settings->credentials['secret_key']);
        $this->assertSame('cmd-hash-value', $settings->webhook_secret);
    }

    public function test_configure_command_refuses_live_mode_and_non_test_keys(): void
    {
        $saccoB = Sacco::factory()->create(['status' => 'active']);

        $this->artisan('payments:configure', ['sacco_id' => $saccoB->id, '--environment' => 'live'])->assertFailed();

        putenv('FLW_V3_SECRET_KEY=FLWSECK-not-a-test-key');
        putenv('FLW_V3_WEBHOOK_HASH=x');
        try {
            $this->artisan('payments:configure', ['sacco_id' => $saccoB->id, '--no-interaction' => true])->assertFailed();
        } finally {
            putenv('FLW_V3_SECRET_KEY');
            putenv('FLW_V3_WEBHOOK_HASH');
        }

        $this->assertSame(0, SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $saccoB->id)->count());
    }

    public function test_the_fake_gateway_drives_the_same_idempotent_path(): void
    {
        SaccoPaymentSetting::withoutTenantScope()->where('sacco_id', $this->sacco->id)->update(['provider' => 'fake', 'credentials' => []]);

        $payment = $this->startDeposit();
        $this->assertSame(0, $this->savings());

        FakeGateway::complete($payment->tx_ref);
        $this->travel(5)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(1, $this->savings());
        $this->assertSame('successful', $payment->fresh()->status);
    }
}
