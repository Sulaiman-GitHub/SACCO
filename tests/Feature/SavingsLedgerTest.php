<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SavingsLedgerTest extends TestCase
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

    public function test_savings_ledger_page_accessible_and_scoped_to_current_sacco(): void
    {
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00001',
            'type' => 'deposit',
            'amount' => 150000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-03-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoB->id,
            'member_id' => $this->memberB->id,
            'transaction_reference' => 'SAV-2026-00002',
            'type' => 'deposit',
            'amount' => 300000,
            'payment_method' => 'mtn_momo',
            'transaction_date' => '2026-03-01',
            'recorded_by' => $this->saccoBAdmin->id,
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.savings.index'));

        $response->assertStatus(200);
        $response->assertSee('SAV-2026-00001');
        $response->assertSee('Grace Atuhaire');
        // Must never see SACCO B's transaction
        $response->assertDontSee('SAV-2026-00002');
        $response->assertDontSee('David Mukasa');
    }

    public function test_loan_officer_can_record_deposit_and_balance_updates_automatically(): void
    {
        $this->assertEquals(0, $this->memberA->savings_balance);

        $response = $this->actingAs($this->saccoAOfficer)->post(route('tenant.savings.store'), [
            'member_id' => $this->memberA->id,
            'type' => 'deposit',
            'amount' => 250000,
            'payment_method' => 'mtn_momo',
            'payment_reference' => 'MM991827364',
            'transaction_date' => '2026-03-10',
            'notes' => 'March monthly contribution',
        ]);

        $saving = Saving::withoutTenantScope()->where('member_id', $this->memberA->id)->first();
        $this->assertNotNull($saving);
        $response->assertRedirect(route('tenant.savings.show', $saving));

        $this->assertEquals($this->saccoA->id, $saving->sacco_id);
        $this->assertEquals('deposit', $saving->type);
        $this->assertEquals(250000.00, $saving->amount);
        $this->assertEquals($this->saccoAOfficer->id, $saving->recorded_by);

        // Core Principle: Running balance computed from append-only ledger entries
        $this->memberA->refresh();
        $this->assertEquals(250000.00, $this->memberA->savings_balance);
        $this->assertEquals(250000.00, $this->memberA->total_deposits);
        $this->assertEquals(0.00, $this->memberA->total_withdrawals);
    }

    public function test_member_can_withdraw_funds_when_balance_is_sufficient(): void
    {
        // Initial deposit
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00010',
            'type' => 'deposit',
            'amount' => 500000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        $this->assertEquals(500000.00, $this->memberA->savings_balance);

        // Withdrawal of 200,000
        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.savings.store'), [
            'member_id' => $this->memberA->id,
            'type' => 'withdrawal',
            'amount' => 200000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-15',
            'notes' => 'Partial savings withdrawal',
        ]);

        $response->assertSessionDoesntHaveErrors();

        $this->memberA->refresh();
        // 500,000 - 200,000 = 300,000
        $this->assertEquals(300000.00, $this->memberA->savings_balance);
        $this->assertEquals(500000.00, $this->memberA->total_deposits);
        $this->assertEquals(200000.00, $this->memberA->total_withdrawals);
    }

    public function test_withdrawal_exceeding_current_balance_is_rejected(): void
    {
        // Member only has 50,000
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00011',
            'type' => 'deposit',
            'amount' => 50000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.savings.store'), [
            'member_id' => $this->memberA->id,
            'type' => 'withdrawal',
            'amount' => 60000, // Exceeds 50,000!
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-02',
        ]);

        $response->assertSessionHasErrors('amount');
        $this->memberA->refresh();
        $this->assertEquals(50000.00, $this->memberA->savings_balance);
    }

    public function test_savings_cannot_be_recorded_for_inactive_member(): void
    {
        $this->memberA->update(['status' => 'inactive']);

        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.savings.store'), [
            'member_id' => $this->memberA->id,
            'type' => 'deposit',
            'amount' => 50000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('member_id');
    }

    public function test_append_only_ledger_prevents_mutating_or_deleting_records(): void
    {
        $saving = Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00099',
            'type' => 'deposit',
            'amount' => 100000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        // Attempting in-place update must throw RuntimeException
        try {
            $saving->update(['amount' => 200000]);
            $this->fail('Expected RuntimeException was not thrown on ledger mutation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Financial ledger records are append-only', $e->getMessage());
        }

        // Attempting in-place deletion must throw RuntimeException
        try {
            $saving->delete();
            $this->fail('Expected RuntimeException was not thrown on ledger deletion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Financial ledger records are append-only', $e->getMessage());
        }
    }

    public function test_tenant_isolation_prevents_cross_sacco_operations(): void
    {
        // SACCO A user trying to post transaction against SACCO B's member
        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.savings.store'), [
            'member_id' => $this->memberB->id,
            'type' => 'deposit',
            'amount' => 50000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('member_id');

        // Create transaction in SACCO B
        $savingB = Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoB->id,
            'member_id' => $this->memberB->id,
            'transaction_reference' => 'SAV-B-001',
            'type' => 'deposit',
            'amount' => 100000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoBAdmin->id,
        ]);

        // SACCO A user cannot view receipt of SACCO B's transaction
        $receiptResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.savings.show', $savingB));
        $receiptResponse->assertStatus(404);

        // SACCO A user cannot view statement of SACCO B's member
        $statementResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.savings_statement', $this->memberB));
        $statementResponse->assertStatus(404);
    }

    public function test_member_statement_calculates_chronological_running_balances(): void
    {
        // Transaction 1: Deposit 100,000 -> Balance: 100,000
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00001',
            'type' => 'deposit',
            'amount' => 100000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        // Transaction 2: Deposit 150,000 -> Balance: 250,000
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00002',
            'type' => 'deposit',
            'amount' => 150000,
            'payment_method' => 'mtn_momo',
            'transaction_date' => '2026-01-15',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        // Transaction 3: Withdrawal 50,000 -> Balance: 200,000
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-00003',
            'type' => 'withdrawal',
            'amount' => 50000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-02-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.savings_statement', $this->memberA));

        $response->assertStatus(200);
        $response->assertSee('Grace Atuhaire');
        $response->assertSee('200,000.00'); // Final balance
        $response->assertSee('250,000.00'); // Total deposits
        $response->assertSee('50,000.00');  // Total withdrawals
    }

    public function test_search_and_filters_in_savings_ledger(): void
    {
        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-ALPHA',
            'type' => 'deposit',
            'amount' => 100000,
            'payment_method' => 'cash',
            'transaction_date' => '2026-01-01',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        Saving::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_id' => $this->memberA->id,
            'transaction_reference' => 'SAV-2026-BETA',
            'type' => 'withdrawal',
            'amount' => 40000,
            'payment_method' => 'mtn_momo',
            'transaction_date' => '2026-01-10',
            'recorded_by' => $this->saccoAAdmin->id,
        ]);

        // Search by reference
        $searchResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.savings.index', ['search' => 'ALPHA']));
        $searchResponse->assertSee('SAV-2026-ALPHA');
        $searchResponse->assertDontSee('SAV-2026-BETA');

        // Filter by type withdrawal
        $filterResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.savings.index', ['type' => 'withdrawal']));
        $filterResponse->assertSee('SAV-2026-BETA');
        $filterResponse->assertDontSee('SAV-2026-ALPHA');

        // Filter by payment method
        $methodResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.savings.index', ['payment_method' => 'cash']));
        $methodResponse->assertSee('SAV-2026-ALPHA');
        $methodResponse->assertDontSee('SAV-2026-BETA');
    }
}
