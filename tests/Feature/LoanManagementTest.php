<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class LoanManagementTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $saccoA;

    private Sacco $saccoB;

    private User $saccoAAdmin;

    private User $saccoAOfficer;

    private User $saccoBAdmin;

    private Member $memberA;

    private Member $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        // SACCO A (Kampala)
        $this->saccoA = Sacco::create([
            'name' => 'Kampala Teachers SACCO',
            'license_number' => 'UMRA/2026/001',
            'tier' => 'Tier 2',
            'status' => 'active',
        ]);

        $this->saccoAAdmin = User::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'name' => 'Kampala Admin',
            'email' => 'admin@kampalateachers.ug',
            'password' => Hash::make('password123'),
            'role' => 'sacco_admin',
            'status' => 'active',
        ]);

        $this->saccoAOfficer = User::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'name' => 'Kampala Loan Officer',
            'email' => 'officer@kampalateachers.ug',
            'password' => Hash::make('password123'),
            'role' => 'loan_officer',
            'status' => 'active',
        ]);

        $this->memberA = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Grace Atuhaire',
            'national_id_hash' => Member::hashNationalId('CF900141029ABC'),
            'national_id_masked' => Member::maskNationalId('CF900141029ABC'),
            'phone' => '+256 772 100200',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        // Provide Member A with initial savings deposit
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00001',
            'type' => 'deposit',
            'amount' => 1500000,
            'payment_method' => 'cash',
            'transaction_date' => '2024-01-15',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        // SACCO B (Jinja)
        $this->saccoB = Sacco::create([
            'name' => 'Jinja Farmers Cooperative',
            'license_number' => 'UMRA/2026/045',
            'tier' => 'Tier 3',
            'status' => 'active',
        ]);

        $this->saccoBAdmin = User::withoutTenantScope()->create([
            'sacco_id' => $this->saccoB->id,
            'name' => 'Jinja Admin',
            'email' => 'admin@jinjafarmers.ug',
            'password' => Hash::make('password123'),
            'role' => 'sacco_admin',
            'status' => 'active',
        ]);

        $this->memberB = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoB->id,
            'member_number' => 'MBR-B001',
            'full_name' => 'David Mukasa',
            'national_id_hash' => Member::hashNationalId('CM850141029XYZ'),
            'national_id_masked' => Member::maskNationalId('CM850141029XYZ'),
            'phone' => '+256 782 500600',
            'join_date' => '2024-02-01',
            'status' => 'active',
        ]);
    }

    public function test_loan_application_can_be_submitted_for_active_member(): void
    {
        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.loans.store'), [
                'member_id' => $this->memberA->id,
                'principal' => 1000000,
                'interest_rate' => 12.0,
                'interest_calculation_type' => 'flat',
                'term_months' => 6,
                'purpose' => 'Retail shop stock restocking',
                'applied_at' => Carbon::now()->format('Y-m-d'),
                'guarantor_name' => 'John Mugisha',
                'guarantor_phone' => '+256 772 333444',
                'guarantor_relationship' => 'Brother',
                'notes' => 'Borrower operates retail store in Nakasero market.',
            ]);

        $loan = Loan::withoutTenantScope()->where('member_id', $this->memberA->id)->first();

        $this->assertNotNull($loan);
        $response->assertRedirect(route('tenant.loans.show', $loan));
        $this->assertEquals('pending', $loan->status);
        $this->assertEquals(1000000.0, (float) $loan->principal);
        // Interest for 1M at 12% for 6 months = 1,000,000 * 0.12 * (6/12) = 60,000
        $this->assertEquals(60000.0, (float) $loan->total_interest);
        $this->assertEquals(1060000.0, (float) $loan->total_amount);
        $this->assertStringStartsWith('LN-', $loan->loan_reference);
    }

    public function test_loan_application_cannot_be_submitted_for_inactive_member(): void
    {
        $this->memberA->update(['status' => 'inactive']);

        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.loans.store'), [
                'member_id' => $this->memberA->id,
                'principal' => 500000,
                'interest_rate' => 10.0,
                'interest_calculation_type' => 'flat',
                'term_months' => 3,
                'applied_at' => Carbon::now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('member_id');
        $this->assertEquals(0, Loan::withoutTenantScope()->count());
    }

    public function test_loan_application_validation_requires_positive_principal_and_term(): void
    {
        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.loans.store'), [
                'member_id' => $this->memberA->id,
                'principal' => 500, // Below min 10000
                'interest_rate' => -5,
                'interest_calculation_type' => 'flat',
                'term_months' => 0,
                'applied_at' => Carbon::tomorrow()->format('Y-m-d'), // Future date invalid
            ]);

        $response->assertSessionHasErrors(['principal', 'interest_rate', 'term_months', 'applied_at']);
    }

    public function test_pending_loan_can_be_approved_by_staff(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00001',
            'principal' => 2000000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 12,
            'total_interest' => 200000,
            'total_amount' => 2200000,
            'status' => 'pending',
            'applied_at' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'created_by' => $this->saccoAOfficer->id,
        ]);

        $response = $this->actingAs($this->saccoAAdmin)
            ->post(route('tenant.loans.approve', $loan), [
                'notes' => 'Vetted and approved by credit committee.',
            ]);

        $response->assertRedirect(route('tenant.loans.show', $loan));

        $loan->refresh();
        $this->assertTrue($loan->isApproved());
        $this->assertNotNull($loan->approved_at);
        $this->assertEquals($this->saccoAAdmin->id, $loan->approved_by);
    }

    public function test_pending_loan_can_be_rejected_with_mandatory_reason(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00002',
            'principal' => 5000000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 12,
            'total_interest' => 500000,
            'total_amount' => 5500000,
            'status' => 'pending',
            'applied_at' => Carbon::now()->subDays(2)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->saccoAAdmin)
            ->post(route('tenant.loans.reject', $loan), [
                'rejection_reason' => 'Requested amount exceeds 3x member current savings balance without sufficient collateral.',
            ]);

        $response->assertRedirect(route('tenant.loans.show', $loan));

        $loan->refresh();
        $this->assertTrue($loan->isRejected());
        $this->assertNotNull($loan->rejected_at);
        $this->assertEquals($this->saccoAAdmin->id, $loan->rejected_by);
        $this->assertStringContainsString('Requested amount exceeds', $loan->rejection_reason);
    }

    public function test_approved_loan_can_be_disbursed_and_calculates_due_date(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00003',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'approved',
            'applied_at' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'approved_at' => Carbon::now()->subDays(3),
            'approved_by' => $this->saccoAAdmin->id,
        ]);

        $disbursementDate = Carbon::now()->format('Y-m-d');

        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.loans.disburse', $loan), [
                'disbursement_method' => 'mtn_momo',
                'disbursement_reference' => 'MM99887766',
                'disbursement_date' => $disbursementDate,
                'notes' => 'Transferred directly to member registered mobile number.',
            ]);

        $response->assertRedirect(route('tenant.loans.show', $loan));

        $loan->refresh();
        $this->assertTrue($loan->isDisbursed());
        $this->assertNotNull($loan->disbursed_at);
        $this->assertEquals('mtn_momo', $loan->disbursement_method);
        $this->assertEquals('MM99887766', $loan->disbursement_reference);
        // Due date = disbursement + 6 months
        $expectedDue = Carbon::parse($disbursementDate)->addMonths(6)->format('Y-m-d');
        $this->assertEquals($expectedDue, $loan->due_date->format('Y-m-d'));
    }

    public function test_disbursed_loan_accepts_append_only_repayment(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00004',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(1)->format('Y-m-d'),
            'approved_at' => Carbon::now()->subMonths(1),
            'disbursed_at' => Carbon::now()->subMonths(1),
            'due_date' => Carbon::now()->addMonths(5)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.repayments.store'), [
                'loan_id' => $loan->id,
                'amount' => 300000,
                'payment_method' => 'cash',
                'repayment_date' => Carbon::now()->format('Y-m-d'),
                'notes' => 'First installment payment.',
            ]);

        $repayment = Repayment::withoutTenantScope()->where('loan_id', $loan->id)->first();
        $this->assertNotNull($repayment);
        $response->assertRedirect(route('tenant.repayments.show', $repayment));

        $this->assertEquals(300000.0, (float) $repayment->amount);
        $this->assertStringStartsWith('REP-', $repayment->repayment_reference);

        // Check loan balance
        $loan->refresh();
        $this->assertEquals(300000.0, $loan->total_repaid);
        $this->assertEquals(750000.0, $loan->remaining_balance);
        $this->assertEquals(28.6, $loan->repayment_percentage); // 300000 / 1050000 = 28.57% -> 28.6%
    }

    public function test_repayment_cannot_exceed_remaining_loan_balance(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00005',
            'principal' => 500000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 3,
            'total_interest' => 12500,
            'total_amount' => 512500,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(1)->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonths(1),
            'due_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.repayments.store'), [
                'loan_id' => $loan->id,
                'amount' => 600000, // Exceeds 512,500
                'payment_method' => 'cash',
                'repayment_date' => Carbon::now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('amount');
        $this->assertEquals(0, Repayment::withoutTenantScope()->count());
    }

    public function test_loan_automatically_transitions_to_closed_when_balance_reaches_zero(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00006',
            'principal' => 500000,
            'interest_rate' => 10.0,
            'interest_calculation_type' => 'flat',
            'term_months' => 3,
            'total_interest' => 12500,
            'total_amount' => 512500,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(1)->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonths(1),
            'due_date' => Carbon::now()->addMonths(2)->format('Y-m-d'),
        ]);

        // Repay full 512,500
        $this->actingAs($this->saccoAOfficer)
            ->post(route('tenant.repayments.store'), [
                'loan_id' => $loan->id,
                'amount' => 512500,
                'payment_method' => 'airtel_money',
                'payment_reference' => 'AM12345678',
                'repayment_date' => Carbon::now()->format('Y-m-d'),
            ]);

        $loan->refresh();
        $this->assertEquals(0.0, $loan->remaining_balance);
        $this->assertEquals('closed', $loan->status);
        $this->assertTrue($loan->isClosed());
    }

    public function test_repayment_ledger_is_append_only_and_cannot_be_mutated(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00007',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->format('Y-m-d'),
        ]);

        $repayment = Repayment::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'loan_id' => $loan->id,
            'member_id' => $this->memberA->id,
            'repayment_reference' => 'REP-2026-00001',
            'amount' => 100000,
            'payment_method' => 'cash',
            'repayment_date' => Carbon::now()->format('Y-m-d'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Loan repayment ledger records are append-only and cannot be mutated in place.');

        $repayment->update(['amount' => 200000]);
    }

    public function test_repayment_ledger_is_append_only_and_cannot_be_deleted(): void
    {
        $loan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00008',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->format('Y-m-d'),
        ]);

        $repayment = Repayment::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'loan_id' => $loan->id,
            'member_id' => $this->memberA->id,
            'repayment_reference' => 'REP-2026-00002',
            'amount' => 100000,
            'payment_method' => 'cash',
            'repayment_date' => Carbon::now()->format('Y-m-d'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Loan repayment ledger records are append-only and cannot be deleted.');

        $repayment->delete();
    }

    public function test_overdue_and_default_detection_policy_enforcement(): void
    {
        // Loan overdue by 40 days (<= 90 days threshold) -> should become 'overdue'
        $overdueLoan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00009',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonths(7),
            'due_date' => Carbon::now()->subDays(40)->format('Y-m-d'),
        ]);

        $status = $overdueLoan->refreshStatus();
        $this->assertEquals('overdue', $status);
        $this->assertTrue($overdueLoan->is_overdue);
        $this->assertEquals(40, $overdueLoan->days_overdue);

        // Loan overdue by 105 days (> 90 days threshold) -> should become 'defaulted'
        $defaultedLoan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00010',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(10)->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonths(9),
            'due_date' => Carbon::now()->subDays(105)->format('Y-m-d'),
        ]);

        $statusDefault = $defaultedLoan->refreshStatus();
        $this->assertEquals('defaulted', $statusDefault);
        $this->assertTrue($defaultedLoan->isDefaulted());
    }

    public function test_artisan_command_scans_and_updates_overdue_loans(): void
    {
        Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00011',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'disbursed_at' => Carbon::now()->subMonths(7),
            'due_date' => Carbon::now()->subDays(50)->format('Y-m-d'),
        ]);

        $this->artisan('loans:check-overdue')
            ->expectsOutputToContain('Scanning loans for overdue and default transitions')
            ->expectsOutputToContain('Current states: Overdue: 1, Defaulted: 0, Auto-Closed: 0')
            ->assertSuccessful();

        $loan = Loan::withoutTenantScope()->where('loan_reference', 'LN-2026-00011')->first();
        $this->assertEquals('overdue', $loan->status);
    }

    public function test_tenant_cannot_view_or_act_on_another_tenants_loans(): void
    {
        $loanA = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00012',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'pending',
            'applied_at' => Carbon::now()->format('Y-m-d'),
        ]);

        // Jinja SACCO admin attempts to view Kampala loan
        $response = $this->actingAs($this->saccoBAdmin)
            ->get(route('tenant.loans.show', $loanA));

        $response->assertNotFound();

        // Jinja SACCO admin attempts to approve Kampala loan
        $responseApprove = $this->actingAs($this->saccoBAdmin)
            ->post(route('tenant.loans.approve', $loanA));

        $responseApprove->assertNotFound();
    }

    public function test_tenant_cannot_repay_another_tenants_loan(): void
    {
        $loanA = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00013',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->format('Y-m-d'),
            'due_date' => Carbon::now()->addMonths(6)->format('Y-m-d'),
        ]);

        // Jinja SACCO attempts to record repayment for Kampala loan
        $response = $this->actingAs($this->saccoBAdmin)
            ->post(route('tenant.repayments.store'), [
                'loan_id' => $loanA->id,
                'amount' => 100000,
                'payment_method' => 'cash',
                'repayment_date' => Carbon::now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('loan_id');
        $this->assertEquals(0, Repayment::withoutTenantScope()->count());
    }

    public function test_member_loan_summary_accessors_and_loan_to_savings_ratio(): void
    {
        // Member A has 1,500,000 savings
        $this->assertEquals(1500000.0, $this->memberA->savings_balance);

        // Requested 3,000,000 -> ratio = 3,000,000 / 1,500,000 = 2.0x
        $ratio = $this->memberA->loanToSavingsRatio(3000000);
        $this->assertEquals(2.0, $ratio);

        // Create 1 completed loan, 1 defaulted loan, 1 active loan
        Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00014',
            'principal' => 500000,
            'interest_rate' => 10.0,
            'term_months' => 3,
            'total_interest' => 12500,
            'total_amount' => 512500,
            'status' => 'closed',
            'applied_at' => Carbon::now()->subMonths(6)->format('Y-m-d'),
        ]);

        Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00015',
            'principal' => 500000,
            'interest_rate' => 10.0,
            'term_months' => 3,
            'total_interest' => 12500,
            'total_amount' => 512500,
            'status' => 'defaulted',
            'applied_at' => Carbon::now()->subMonths(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(100)->format('Y-m-d'),
        ]);

        $activeLoan = Loan::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'loan_reference' => 'LN-2026-00016',
            'principal' => 1000000,
            'interest_rate' => 10.0,
            'term_months' => 6,
            'total_interest' => 50000,
            'total_amount' => 1050000,
            'status' => 'disbursed',
            'applied_at' => Carbon::now()->subMonths(1)->format('Y-m-d'),
            'due_date' => Carbon::now()->addMonths(5)->format('Y-m-d'),
        ]);

        $this->assertEquals(1, $this->memberA->past_loans_count);
        $this->assertEquals(1, $this->memberA->past_default_count);
        // Outstanding balance: 512500 (defaulted) + 1050000 (disbursed) = 1562500
        $this->assertEquals(1562500.0, $this->memberA->total_loan_balance);
    }

    public function test_overdue_scan_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'loans:check-overdue'));

        $this->assertCount(1, $events);
        $this->assertSame('0 1 * * *', $events->first()->expression);
    }
}
