<?php

namespace Tests\Feature;

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $saccoA;

    private Sacco $saccoB;

    private User $superAdmin;

    private User $saccoAAdmin;

    private User $saccoAOfficer;

    private User $saccoBAdmin;

    public function test_signed_in_user_is_restored_from_the_session_on_the_next_request(): void
    {
        $this->post(route('login'), ['email' => $this->saccoAAdmin->email, 'password' => 'password123'])
            ->assertRedirect(route('tenant.dashboard'));

        // A real browser request starts with no cached user, so the guard must load it from the session.
        // That lookup runs a User query, which used to recurse through the tenant scope until memory ran out.
        Auth::forgetGuards();

        $this->get(route('tenant.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($this->saccoAAdmin);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Platform Super Admin
        $this->superAdmin = User::withoutTenantScope()->create([
            'sacco_id' => null,
            'name' => 'Platform Operator',
            'email' => 'super@fattech.ug',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

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

    public function test_login_page_is_accessible_to_guests(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('SACCO Credit Intelligence');
    }

    public function test_super_admin_can_login_and_access_platform_overview(): void
    {
        $response = $this->post('/login', [
            'email' => 'super@fattech.ug',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('super_admin.dashboard'));
        $this->assertAuthenticatedAs($this->superAdmin);

        $dashboardResponse = $this->actingAs($this->superAdmin)->get(route('super_admin.dashboard'));
        $dashboardResponse->assertStatus(200);
        $dashboardResponse->assertSee('Kampala Teachers SACCO');
        $dashboardResponse->assertSee('Jinja Farmers Cooperative');
    }

    public function test_super_admin_can_onboard_new_sacco_and_admin(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('super_admin.saccos.store'), [
            'name' => 'Gulu Community SACCO',
            'license_number' => 'UMRA/2026/099',
            'tier' => 'Tier 4',
            'email' => 'contact@gulu.ug',
            'phone' => '+256 700 999888',
            'address' => 'Gulu Main Market Street',
            'admin_name' => 'Moses Okot',
            'admin_email' => 'okot@gulu.ug',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'admin_phone' => '+256 772 999888',
        ]);

        $response->assertRedirect(route('super_admin.saccos.index'));
        $this->assertDatabaseHas('saccos', ['name' => 'Gulu Community SACCO']);
        $this->assertDatabaseHas('users', ['email' => 'okot@gulu.ug', 'role' => 'sacco_admin']);
    }

    public function test_sacco_admin_can_login_and_access_tenant_workspace(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@kampalateachers.ug',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('tenant.dashboard'));
        $this->assertAuthenticatedAs($this->saccoAAdmin);

        $dashboardResponse = $this->actingAs($this->saccoAAdmin)->get(route('tenant.dashboard'));
        $dashboardResponse->assertStatus(200);
        $dashboardResponse->assertSee('Kampala Teachers SACCO');
        // Must never see another SACCO's details on its dashboard
        $dashboardResponse->assertDontSee('Jinja Farmers Cooperative');
    }

    public function test_tenant_query_level_isolation_enforced(): void
    {
        // When logged in as SACCO A Admin, queries must only return SACCO A users
        $this->actingAs($this->saccoAAdmin);

        $visibleUsers = User::all();
        $this->assertCount(2, $visibleUsers);
        $this->assertTrue($visibleUsers->contains('email', 'admin@kampalateachers.ug'));
        $this->assertTrue($visibleUsers->contains('email', 'officer@kampalateachers.ug'));
        $this->assertFalse($visibleUsers->contains('email', 'admin@jinjafarmers.ug'));

        // When logged in as SACCO B Admin, queries must only return SACCO B users
        $this->actingAs($this->saccoBAdmin);

        $visibleUsersB = User::all();
        $this->assertCount(1, $visibleUsersB);
        $this->assertTrue($visibleUsersB->contains('email', 'admin@jinjafarmers.ug'));
        $this->assertFalse($visibleUsersB->contains('email', 'admin@kampalateachers.ug'));
        $this->assertFalse($visibleUsersB->contains('email', 'officer@kampalateachers.ug'));
    }

    public function test_sacco_admin_staff_page_does_not_surface_other_sacco_data(): void
    {
        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.staff.index'));
        $response->assertStatus(200);
        $response->assertSee('Kampala Admin');
        $response->assertSee('Kampala Loan Officer');
        $response->assertDontSee('Jinja Admin');
    }

    public function test_sacco_admin_cannot_access_super_admin_routes(): void
    {
        $response = $this->actingAs($this->saccoAAdmin)->get(route('super_admin.dashboard'));
        $response->assertStatus(403);
    }

    public function test_suspended_sacco_is_denied_access(): void
    {
        $this->saccoA->update(['status' => 'suspended']);

        $response = $this->actingAs($this->saccoAAdmin)->get(route('tenant.dashboard'));
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_new_record_automatically_assigns_tenant_sacco_id(): void
    {
        $this->actingAs($this->saccoAAdmin);

        // Created without passing sacco_id explicitly
        $newUser = User::create([
            'name' => 'Auto Tenant Officer',
            'email' => 'autotenant@kampalateachers.ug',
            'password' => Hash::make('password123'),
            'role' => 'loan_officer',
            'status' => 'active',
        ]);

        $this->assertEquals($this->saccoA->id, $newUser->sacco_id);
    }
}
