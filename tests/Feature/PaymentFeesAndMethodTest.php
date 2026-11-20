<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Sacco;
use App\Models\SaccoPaymentSetting;
use App\Models\Saving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFeesAndMethodTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'fees-test-hash';

    private Sacco $sacco;

    private User $admin;

    private User $officer;

    private Member $member;

    /** @var array<string, mixed>|null */
    private ?array $verifyData = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->member = Member::factory()->consented($this->admin)->create(['sacco_id' => $this->sacco->id, 'status' => 'active', 'full_name' => 'Fee Tester']);

        SaccoPaymentSetting::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id, 'provider' => 'flutterwave', 'environment' => 'sandbox',
            'credentials' => ['secret_key' => 'FLWSECK_TEST-x'], 'webhook_secret' => self::HASH, 'enabled' => true,
        ]);

        Http::fake([
            '*/v3/transactions/*' => fn (HttpRequest $r) => Http::response(['status' => 'success', 'data' => $this->verifyData]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payment(array $attributes = [], ?Sacco $sacco = null, ?Member $member = null): PaymentTransaction
    {
        $sacco ??= $this->sacco;
        $member ??= $this->member;

        return PaymentTransaction::withoutTenantScope()->create(array_merge([
            'sacco_id' => $sacco->id, 'member_id' => $member->id, 'purpose' => 'deposit',
            'tx_ref' => 'PAY-'.$sacco->id.'-'.strtoupper(bin2hex(random_bytes(6))), 'provider' => 'flutterwave',
            'amount' => 5000, 'currency' => 'UGX', 'network' => 'mtn', 'status' => 'successful', 'fee' => 250,
            'completed_at' => now(),
        ], $attributes));
    }

    private function confirmByWebhook(PaymentTransaction $payment, string $providerType, ?float $fee = 100.0): void
    {
        $this->verifyData = [
            'id' => 5, 'tx_ref' => $payment->tx_ref, 'flw_ref' => 'FLW-'.$payment->id, 'status' => 'successful',
            'amount' => (float) $payment->amount, 'currency' => 'UGX', 'app_fee' => $fee, 'payment_type' => $providerType,
        ];

        $this->postJson(route('webhooks.flutterwave', $this->sacco), ['event' => 'charge.completed', 'data' => ['id' => 5, 'tx_ref' => $payment->tx_ref]], ['verif-hash' => self::HASH])->assertOk();
    }

    public function test_the_ledger_records_how_the_member_really_paid_not_what_they_picked(): void
    {
        $cases = [
            // [picked on our form, provider payment type, network afterwards, method on the ledger row]
            'picked card, paid by mobile money' => ['card', 'mobilemoneyug', 'momo', 'mobile_money'],
            'picked MTN, paid by mobile money' => ['mtn', 'mobilemoneyug', 'mtn', 'mtn_momo'],
            'picked Airtel, paid by mobile money' => ['airtel', 'mobilemoneyug', 'airtel', 'airtel_money'],
            'picked MTN, actually paid by card' => ['mtn', 'card', 'card', 'other'],
            'picked card, paid by card' => ['card', 'card', 'card', 'other'],
            'picked Airtel, provider type unknown' => ['airtel', 'something_new', 'airtel', 'airtel_money'],
        ];

        foreach ($cases as $name => [$picked, $type, $network, $method]) {
            $payment = $this->payment(['status' => 'pending', 'network' => $picked, 'fee' => null]);
            $this->confirmByWebhook($payment, $type);

            $payment->refresh();
            $saving = Saving::withoutTenantScope()->findOrFail($payment->saving_id);
            $this->assertSame($network, $payment->network, $name);
            $this->assertSame($method, $saving->payment_method, $name);
        }
    }

    public function test_the_fee_is_saved_but_the_member_is_credited_the_full_amount(): void
    {
        $payment = $this->payment(['status' => 'pending', 'amount' => 5000, 'fee' => null]);
        $this->confirmByWebhook($payment, 'mobilemoneyug', 250.0);

        $payment->refresh();
        $this->assertEquals(250.0, (float) $payment->fee);
        $this->assertSame(4750.0, $payment->netAmount());
        $this->assertEquals(5000.0, $this->member->fresh()->savings_balance);
    }

    public function test_net_amount_is_unknown_when_the_provider_reported_no_fee(): void
    {
        $this->assertNull($this->payment(['fee' => null])->netAmount());
        $this->assertSame(0.0, $this->payment(['fee' => 0, 'amount' => 0.0 + 1000, 'tx_ref' => 'PAY-ZERO'])->netAmount() - 1000.0);
    }

    public function test_staff_see_fee_net_and_totals_for_received_payments_only(): void
    {
        $this->payment(['amount' => 5000, 'fee' => 250]);
        $this->payment(['amount' => 50000, 'fee' => 2500]);
        $this->payment(['amount' => 8000, 'fee' => null]); // received, no fee reported
        $this->payment(['amount' => 9000, 'fee' => 450, 'status' => 'failed']); // never counts
        $this->payment(['amount' => 7000, 'fee' => null, 'status' => 'pending']); // never counts

        $other = Sacco::factory()->create(['status' => 'active']);
        $otherMember = Member::factory()->create(['sacco_id' => $other->id, 'status' => 'active']);
        $this->payment(['amount' => 999000, 'fee' => 9990], $other, $otherMember); // another SACCO never counts

        $this->actingAs($this->officer, 'web');
        Auth::shouldUse('web');
        $response = $this->get(route('tenant.payments.index'))->assertOk();

        $response->assertSee('Provider fees')->assertSee('Net to the SACCO');
        $totals = $response->viewData('totals');
        $this->assertSame(3, $totals['count']);
        $this->assertSame(63000.0, $totals['gross']);
        $this->assertSame(2750.0, $totals['fees']);
        $this->assertSame(1, $totals['withoutFee']);
        $this->assertSame(52250.0, $totals['net']); // (5000-250) + (50000-2500); the no-fee payment is left out
        $response->assertSee('Leaves out 1 payment(s) with no fee reported')->assertSee('4,750.00')->assertSee('47,500.00');

        // Totals follow the filters
        $filtered = $this->get(route('tenant.payments.index', ['status' => 'failed']))->viewData('totals');
        $this->assertSame(0, $filtered['count']);
    }

    public function test_only_admins_can_export_payments_and_the_file_has_fee_and_net(): void
    {
        $this->payment(['amount' => 5000, 'fee' => 250, 'provider_reference' => 'FLW-ONE']);
        $this->payment(['amount' => 6000, 'fee' => null, 'status' => 'pending']);

        $this->actingAs($this->officer, 'web');
        Auth::shouldUse('web');
        $this->get(route('tenant.reports.payments_csv'))->assertForbidden();

        $this->actingAs($this->admin, 'web');
        $csv = $this->get(route('tenant.reports.payments_csv'))->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertStringContainsString('Provider Fee (UGX)', $lines[0]);
        $this->assertStringContainsString('Net To SACCO (UGX)', $lines[0]);
        $this->assertCount(2, $lines, 'Received payments only by default: header plus one row');
        $this->assertStringContainsString('Fee Tester', $lines[1]);
        $this->assertStringContainsString('5000,250,4750', $lines[1]);
        $this->assertStringContainsString('FLW-ONE', $lines[1]);

        $all = $this->get(route('tenant.reports.payments_csv', ['scope' => 'all']))->streamedContent();
        $this->assertCount(3, array_filter(explode("\n", $all)));
        $this->assertStringContainsString('pending', $all);
    }

    public function test_the_payments_export_respects_dates_isolates_saccos_and_neutralises_formulas(): void
    {
        $old = $this->payment(['tx_ref' => 'PAY-OLD-ONE']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->payment(['tx_ref' => 'PAY-NEW-ONE']);

        $evil = Member::factory()->create(['sacco_id' => $this->sacco->id, 'status' => 'active', 'full_name' => '=HYPERLINK("http://evil")']);
        $this->payment(['tx_ref' => 'PAY-EVIL-ONE'], null, $evil);

        $other = Sacco::factory()->create(['status' => 'active']);
        $this->payment(['tx_ref' => 'PAY-OTHER-SACCO'], $other, Member::factory()->create(['sacco_id' => $other->id, 'status' => 'active']));

        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');

        $csv = $this->get(route('tenant.reports.payments_csv', ['start_date' => now()->subDays(7)->toDateString()]))->streamedContent();
        $this->assertStringContainsString('PAY-NEW-ONE', $csv);
        $this->assertStringNotContainsString('PAY-OLD-ONE', $csv);
        $this->assertStringNotContainsString('PAY-OTHER-SACCO', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);

        $this->get(route('tenant.reports.payments_csv', ['start_date' => '2026-02-01', 'end_date' => '2026-01-01']))->assertSessionHasErrors('end_date');
        $this->get(route('tenant.reports.payments_csv', ['scope' => 'everything']))->assertSessionHasErrors('scope');
    }

    public function test_the_reports_page_offers_the_payments_export(): void
    {
        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');

        $this->get(route('tenant.reports.index'))->assertOk()
            ->assertSee('Export online payments (CSV)')
            ->assertSee(route('tenant.reports.payments_csv'));
    }

    public function test_savings_can_be_filtered_by_the_mobile_money_method(): void
    {
        $this->actingAs($this->admin, 'web');
        Auth::shouldUse('web');

        $mobile = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'payment_method' => 'mobile_money', 'payment_reference' => 'REF-MOBILE']);
        $cash = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'payment_method' => 'cash', 'payment_reference' => 'REF-CASH']);

        $this->get(route('tenant.savings.index', ['payment_method' => 'mobile_money']))->assertOk()
            ->assertSee($mobile->transaction_reference)
            ->assertDontSee($cash->transaction_reference)
            ->assertSee('Mobile money');
    }
}
