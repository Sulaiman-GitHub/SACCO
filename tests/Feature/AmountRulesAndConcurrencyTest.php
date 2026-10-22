<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AmountRulesAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $officer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::create([
            'name' => 'Amount Rules SACCO',
            'license_number' => 'UMRA/2026/777',
            'tier' => 'Tier 2',
            'status' => 'active',
        ]);

        $this->officer = User::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id,
            'name' => 'Officer',
            'email' => 'officer@amountrules.ug',
            'password' => Hash::make('password123'),
            'role' => 'loan_officer',
            'status' => 'active',
        ]);

        $this->member = Member::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Amount Tester',
            'national_id_hash' => Member::hashNationalId('CF900141029XYZ'),
            'national_id_masked' => Member::maskNationalId('CF900141029XYZ'),
            'phone' => '+256 772 000111',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);
    }

    private function loanWithBalance(float $totalAmount, string $reference): Loan
    {
        return Loan::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id,
            'member_id' => $this->member->id,
            'loan_reference' => $reference,
            'principal' => $totalAmount,
            'interest_rate' => 0,
            'interest_calculation_type' => 'flat',
            'term_months' => 3,
            'total_interest' => 0,
            'total_amount' => $totalAmount,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonth()->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonth(),
            'due_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
        ]);
    }

    private function deposit(float $amount): Saving
    {
        return Saving::recordLocked([
            'member_id' => $this->member->id,
            'type' => 'deposit',
            'amount' => $amount,
            'payment_method' => 'cash',
            'transaction_date' => Carbon::now()->format('Y-m-d'),
        ], $this->sacco->id, $this->officer->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function repaymentPayload(Loan $loan, float $amount): array
    {
        return [
            'loan_id' => $loan->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'repayment_date' => Carbon::now()->format('Y-m-d'),
        ];
    }

    public function test_a_balance_of_322500_can_be_paid_in_one_payment(): void
    {
        $loan = $this->loanWithBalance(322500, 'LN-2026-90001');

        $this->actingAs($this->officer)
            ->post(route('tenant.repayments.store'), $this->repaymentPayload($loan, 322500))
            ->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertEquals(0.0, $loan->remaining_balance);
        $this->assertEquals('closed', $loan->status);
    }

    public function test_a_final_repayment_below_the_minimum_is_allowed_when_it_clears_the_balance(): void
    {
        $loan = $this->loanWithBalance(500, 'LN-2026-90002');

        $this->actingAs($this->officer)
            ->post(route('tenant.repayments.store'), $this->repaymentPayload($loan, 500))
            ->assertSessionHasNoErrors();

        $this->assertEquals('closed', $loan->fresh()->status);
    }

    public function test_a_partial_repayment_below_the_minimum_is_rejected_with_a_plain_message(): void
    {
        $loan = $this->loanWithBalance(50000, 'LN-2026-90003');

        $response = $this->actingAs($this->officer)
            ->post(route('tenant.repayments.store'), $this->repaymentPayload($loan, 500));

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString('minimum repayment is UGX 1,000', session('errors')->first('amount'));
        $this->assertEquals(0, Repayment::withoutTenantScope()->count());
    }

    public function test_repayment_amounts_are_not_restricted_to_steps(): void
    {
        $loan = $this->loanWithBalance(50000, 'LN-2026-90004');

        $this->actingAs($this->officer)
            ->post(route('tenant.repayments.store'), $this->repaymentPayload($loan, 1234.56))
            ->assertSessionHasNoErrors();

        $this->assertEquals(48765.44, $loan->fresh()->remaining_balance);
    }

    public function test_a_withdrawal_that_empties_the_account_is_allowed_below_the_minimum(): void
    {
        $this->deposit(5000);
        Saving::recordLocked([
            'member_id' => $this->member->id,
            'type' => 'withdrawal',
            'amount' => 4200,
            'payment_method' => 'cash',
            'transaction_date' => Carbon::now()->format('Y-m-d'),
        ], $this->sacco->id, $this->officer->id);
        $this->assertEquals(800.0, $this->member->fresh()->savings_balance);

        $this->actingAs($this->officer)
            ->post(route('tenant.savings.store'), [
                'member_id' => $this->member->id,
                'type' => 'withdrawal',
                'amount' => 800,
                'payment_method' => 'cash',
                'transaction_date' => Carbon::now()->format('Y-m-d'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0.0, $this->member->fresh()->savings_balance);
    }

    public function test_a_partial_withdrawal_below_the_minimum_is_rejected(): void
    {
        $this->deposit(50000);

        $response = $this->actingAs($this->officer)
            ->post(route('tenant.savings.store'), [
                'member_id' => $this->member->id,
                'type' => 'withdrawal',
                'amount' => 400,
                'payment_method' => 'cash',
                'transaction_date' => Carbon::now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('amount');
        $this->assertEquals(50000.0, $this->member->fresh()->savings_balance);
    }

    public function test_a_deposit_below_the_minimum_is_still_rejected(): void
    {
        $this->actingAs($this->officer)
            ->post(route('tenant.savings.store'), [
                'member_id' => $this->member->id,
                'type' => 'deposit',
                'amount' => 500,
                'payment_method' => 'cash',
                'transaction_date' => Carbon::now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_overlapping_repayments_cannot_overpay_the_loan(): void
    {
        $loan = $this->loanWithBalance(100000, 'LN-2026-90005');

        // Both requests passed form validation against the same 100,000 balance
        // (80,000 each is fine on its own). Only one can fit once the loan is locked.
        Repayment::recordLocked($this->repaymentPayload($loan, 80000), $this->sacco->id, $this->officer->id);

        try {
            Repayment::recordLocked($this->repaymentPayload($loan, 80000), $this->sacco->id, $this->officer->id);
            $this->fail('The second overlapping repayment should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertEquals(1, Repayment::withoutTenantScope()->count());
        $this->assertEquals(20000.0, $loan->fresh()->remaining_balance);
    }

    public function test_overlapping_withdrawals_cannot_overdraw_the_account(): void
    {
        $this->deposit(100000);

        $withdraw = fn () => Saving::recordLocked([
            'member_id' => $this->member->id,
            'type' => 'withdrawal',
            'amount' => 80000,
            'payment_method' => 'cash',
            'transaction_date' => Carbon::now()->format('Y-m-d'),
        ], $this->sacco->id, $this->officer->id);

        $withdraw();
        $this->expectException(ValidationException::class);
        $withdraw();
    }

    public function test_references_come_from_a_per_sacco_counter_without_duplicates(): void
    {
        $other = Sacco::create(['name' => 'Other SACCO', 'license_number' => 'UMRA/2026/778', 'tier' => 'Tier 2', 'status' => 'active']);

        $refs = [];
        for ($i = 0; $i < 3; $i++) {
            $refs[] = Repayment::generateReference($this->sacco->id);
        }

        $this->assertCount(3, array_unique($refs));
        $this->assertSame(sprintf('REP-%s-00001', date('Y')), $refs[0]);
        $this->assertSame(sprintf('REP-%s-00003', date('Y')), $refs[2]);
        // Another SACCO and another document type have their own counters
        $this->assertSame(sprintf('REP-%s-00001', date('Y')), Repayment::generateReference($other->id));
        $this->assertSame(sprintf('SAV-%s-00001', date('Y')), Saving::generateReference($this->sacco->id));
        $this->assertSame(sprintf('LN-%s-00001', date('Y')), Loan::generateReference($this->sacco->id));
    }

    public function test_a_rolled_back_save_does_not_burn_a_reference_and_preview_does_not_reserve(): void
    {
        $this->assertSame(1, DocumentSequence::peek($this->sacco->id, 'repayment'));
        $this->assertSame(1, DocumentSequence::peek($this->sacco->id, 'repayment'));

        $loan = $this->loanWithBalance(10000, 'LN-2026-90006');
        try {
            Repayment::recordLocked($this->repaymentPayload($loan, 20000), $this->sacco->id, $this->officer->id);
        } catch (ValidationException) {
            // expected: exceeds the balance
        }

        $this->assertSame(1, DocumentSequence::peek($this->sacco->id, 'repayment'));
    }

    public function test_new_counter_starts_above_references_already_issued(): void
    {
        $loan = $this->loanWithBalance(100000, 'LN-2026-90007');
        Repayment::withoutTenantScope()->create([
            'sacco_id' => $this->sacco->id,
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'repayment_reference' => sprintf('REP-%s-00001', date('Y')),
            'amount' => 1000,
            'payment_method' => 'cash',
            'repayment_date' => Carbon::now()->format('Y-m-d'),
        ]);

        $this->assertNotSame(sprintf('REP-%s-00001', date('Y')), Repayment::generateReference($this->sacco->id));
    }
}
