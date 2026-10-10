<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $saccoA;

    private Sacco $saccoB;

    private User $saccoAAdmin;

    private User $saccoAOfficer;

    private User $saccoBAdmin;

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
            'name' => 'Kampala Officer',
            'email' => 'officer@kampalateachers.ug',
            'password' => Hash::make('password123'),
            'role' => 'loan_officer',
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
    }

    public function test_sacco_admin_can_view_members_index(): void
    {
        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Okello Ronald',
            'national_id_hash' => Member::hashNationalId('CM820141029ABC'),
            'national_id_masked' => Member::maskNationalId('CM820141029ABC'),
            'phone' => '+256 772 123456',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index'));

        $response->assertStatus(200);
        $response->assertSee('Okello Ronald');
        $response->assertSee('MBR-0001');
    }

    public function test_sacco_admin_can_register_new_member_with_hashed_nin(): void
    {
        $rawNin = 'CM950141088KLM';
        $expectedHash = Member::hashNationalId($rawNin);
        $expectedMask = Member::maskNationalId($rawNin);

        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.members.store'), [
            'member_number' => 'MBR-0002',
            'full_name' => 'Sarah Namubiru',
            'national_id' => $rawNin,
            'phone' => '+256 701 987654',
            'email' => 'sarah@example.ug',
            'date_of_birth' => '1995-04-12',
            'gender' => 'female',
            'occupation' => 'Produce Trader',
            'address' => 'Kawempe Division, Kampala',
            'monthly_income' => 1200000,
            'next_of_kin_name' => 'John Kato',
            'next_of_kin_phone' => '+256 772 112233',
            'next_of_kin_relationship' => 'Brother',
            'join_date' => '2024-03-01',
            'status' => 'active',
            'notes' => 'Registered during quarterly outreach.',
            'consent_data_processing' => '1',
            'scoring_consent' => '1',
            'next_of_kin_informed' => '1',
        ]);

        $member = Member::withoutTenantScope()->where('member_number', 'MBR-0002')->first();

        $this->assertNotNull($member);
        $response->assertRedirect(route('tenant.members.show', $member));

        // Multi-tenant check
        $this->assertEquals($this->saccoA->id, $member->sacco_id);
        $this->assertEquals('Sarah Namubiru', $member->full_name);

        // Sensitive National ID check: Hash stored, raw NIN NOT stored
        $this->assertEquals($expectedHash, $member->national_id_hash);
        $this->assertEquals($expectedMask, $member->national_id_masked);
        $this->assertStringNotContainsString($rawNin, (string) $member->toJson());

        $this->assertEquals($this->saccoAAdmin->id, $member->created_by);
    }

    public function test_duplicate_member_number_within_same_sacco_is_rejected(): void
    {
        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Existing Member',
            'national_id_hash' => Member::hashNationalId('CM800000000AAA'),
            'national_id_masked' => Member::maskNationalId('CM800000000AAA'),
            'phone' => '+256 772 000001',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.members.store'), [
            'member_number' => 'MBR-0001', // duplicate
            'full_name' => 'New Duplicate Member',
            'national_id' => 'CM900000000BBB',
            'phone' => '+256 772 000002',
            'join_date' => '2024-02-01',
        ]);

        $response->assertSessionHasErrors('member_number');
    }

    public function test_duplicate_national_id_hash_within_same_sacco_is_rejected(): void
    {
        $sharedNin = 'CM850123456XYZ';

        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Member Alpha',
            'national_id_hash' => Member::hashNationalId($sharedNin),
            'national_id_masked' => Member::maskNationalId($sharedNin),
            'phone' => '+256 772 000001',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->saccoAAdmin)->post(route('tenant.members.store'), [
            'member_number' => 'MBR-0002',
            'full_name' => 'Member Duplicate NIN',
            'national_id' => $sharedNin, // duplicate NIN
            'phone' => '+256 772 000002',
            'join_date' => '2024-02-01',
        ]);

        $response->assertSessionHasErrors('national_id_hash');
    }

    public function test_same_member_number_or_nin_is_allowed_in_different_saccos(): void
    {
        $sharedNin = 'CF900141029ABC';

        // Member in SACCO A
        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0001',
            'full_name' => 'Member in Kampala',
            'national_id_hash' => Member::hashNationalId($sharedNin),
            'national_id_masked' => Member::maskNationalId($sharedNin),
            'phone' => '+256 772 111111',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        // SACCO B can use the same member_number and same NIN without cross-tenant conflict
        $response = $this->actingAs($this->saccoBAdmin)->post(route('tenant.members.store'), [
            'member_number' => 'MBR-0001',
            'full_name' => 'Member in Jinja',
            'national_id' => $sharedNin,
            'phone' => '+256 702 222222',
            'join_date' => '2024-01-01',
            'consent_data_processing' => '1',
            'scoring_consent' => '0',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals(2, Member::withoutTenantScope()->where('member_number', 'MBR-0001')->count());
    }

    public function test_tenant_isolation_prevents_viewing_other_sacco_members(): void
    {
        $memberA = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-A1',
            'full_name' => 'Kampala Member 1',
            'national_id_hash' => Member::hashNationalId('CM111111111111'),
            'national_id_masked' => Member::maskNationalId('CM111111111111'),
            'phone' => '+256 772 111111',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $memberB = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoB->id,
            'member_number' => 'MBR-B1',
            'full_name' => 'Jinja Member 1',
            'national_id_hash' => Member::hashNationalId('CM222222222222'),
            'national_id_masked' => Member::maskNationalId('CM222222222222'),
            'phone' => '+256 702 222222',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        // SACCO A user viewing index should see Member A but never Member B
        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index'));
        $response->assertStatus(200);
        $response->assertSee('Kampala Member 1');
        $response->assertDontSee('Jinja Member 1');

        // SACCO A user attempting to view Member B's profile directly gets 404
        $profileResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.show', $memberB));
        $profileResponse->assertStatus(404);

        // SACCO A user attempting to edit Member B gets 404
        $editResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.edit', $memberB));
        $editResponse->assertStatus(404);

        // SACCO A user attempting to toggle status of Member B gets 404
        $toggleResponse = $this->actingAs($this->saccoAAdmin)->post(route('tenant.members.toggle_status', $memberB));
        $toggleResponse->assertStatus(404);
    }

    public function test_members_search_and_filtering(): void
    {
        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0010',
            'full_name' => 'Julius Musoke',
            'national_id_hash' => Member::hashNationalId('CM880141029ABC'),
            'national_id_masked' => Member::maskNationalId('CM880141029ABC'),
            'phone' => '+256 772 555666',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0020',
            'full_name' => 'Agnes Nakitto',
            'national_id_hash' => Member::hashNationalId('CF920141029XYZ'),
            'national_id_masked' => Member::maskNationalId('CF920141029XYZ'),
            'phone' => '+256 701 777888',
            'join_date' => '2024-01-01',
            'status' => 'inactive',
        ]);

        // Search by name
        $nameSearch = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index', ['search' => 'Musoke']));
        $nameSearch->assertSee('Julius Musoke');
        $nameSearch->assertDontSee('Agnes Nakitto');

        // Search by phone
        $phoneSearch = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index', ['search' => '777888']));
        $phoneSearch->assertSee('Agnes Nakitto');
        $phoneSearch->assertDontSee('Julius Musoke');

        // Filter by status = active
        $statusSearch = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index', ['status' => 'active']));
        $statusSearch->assertSee('Julius Musoke');
        $statusSearch->assertDontSee('Agnes Nakitto');

        // Filter by status = inactive
        $inactiveSearch = $this->actingAs($this->saccoAAdmin)->get(route('tenant.members.index', ['status' => 'inactive']));
        $inactiveSearch->assertSee('Agnes Nakitto');
        $inactiveSearch->assertDontSee('Julius Musoke');
    }

    public function test_sacco_admin_can_update_member_and_toggle_status(): void
    {
        $member = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0050',
            'full_name' => 'Dennis Kato',
            'national_id_hash' => Member::hashNationalId('CM770141029ABC'),
            'national_id_masked' => Member::maskNationalId('CM770141029ABC'),
            'phone' => '+256 772 333444',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $updateResponse = $this->actingAs($this->saccoAAdmin)->put(route('tenant.members.update', $member), [
            'member_number' => 'MBR-0050',
            'full_name' => 'Dennis Kato Musisi',
            'phone' => '+256 772 999111',
            'occupation' => 'Hardware Retailer',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $updateResponse->assertRedirect(route('tenant.members.show', $member));

        $member->refresh();
        $this->assertEquals('Dennis Kato Musisi', $member->full_name);
        $this->assertEquals('+256 772 999111', $member->phone);
        $this->assertEquals('Hardware Retailer', $member->occupation);

        // Status toggle to inactive
        $toggleResponse = $this->actingAs($this->saccoAAdmin)->post(route('tenant.members.toggle_status', $member));
        $toggleResponse->assertRedirect();

        $member->refresh();
        $this->assertEquals('inactive', $member->status);
    }

    public function test_tenure_months_calculation_for_ml_features(): void
    {
        // 12 months ago
        $member = Member::withoutTenantScope()->create([
            'sacco_id' => $this->saccoA->id,
            'member_number' => 'MBR-0077',
            'full_name' => 'Tenure Test Member',
            'national_id_hash' => Member::hashNationalId('CM990141029ABC'),
            'national_id_masked' => Member::maskNationalId('CM990141029ABC'),
            'phone' => '+256 772 444555',
            'join_date' => Carbon::now()->subMonths(14)->format('Y-m-d'),
            'status' => 'active',
        ]);

        $this->assertEquals(14, $member->tenure_months);
    }
}
