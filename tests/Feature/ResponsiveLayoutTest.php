<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Markup-level guards for the responsive layout. They cannot check how a page looks, but they stop
 * the pieces the mobile layout depends on (viewport tag, off-canvas menu, stacked-table labels,
 * portal tab bar) from being removed by accident.
 */
class ResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
    }

    public function test_staff_pages_have_the_viewport_tag_and_an_off_canvas_menu(): void
    {
        $this->actingAs($this->admin)->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('name="viewport" content="width=device-width, initial-scale=1.0"', false)
            ->assertSee('data-nav-toggle', false)
            ->assertSee('id="app-sidebar"', false)
            ->assertSee('sidebar-backdrop', false)
            ->assertSee('topbar-actions', false);
    }

    public function test_the_stale_modules_roadmap_is_not_in_the_sidebar(): void
    {
        $this->actingAs($this->admin)->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertDontSee('Modules Roadmap')
            ->assertDontSee('Audit Ledger');
    }

    public function test_list_tables_stack_into_cards_with_labelled_cells_on_phones(): void
    {
        Member::factory()->create(['sacco_id' => $this->sacco->id]);

        $this->actingAs($this->admin)->get(route('tenant.members.index'))
            ->assertOk()
            ->assertSee('table-stack', false)
            ->assertSee('data-label="Full Name"', false)
            ->assertSee('data-label="Status"', false);

        foreach (['tenant.loans.index', 'tenant.savings.index', 'tenant.repayments.index', 'tenant.staff.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('table-stack', false);
        }
    }

    public function test_member_portal_has_a_bottom_tab_bar_and_phone_friendly_lists(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id, 'portal_enabled' => true, 'portal_must_change_password' => false]);
        $this->actingAs($member, 'member');
        Auth::shouldUse('web');

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('width=device-width, initial-scale=1.0', false)
            ->assertSee('<nav class="portal-bottomnav"', false);

        $this->get(route('portal.savings'))->assertOk()->assertSee('txn-list', false);
        $this->get(route('portal.loans'))->assertOk();
        $this->get(route('portal.consent'))->assertOk();
    }

    public function test_the_forced_password_change_page_hides_the_portal_navigation(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id, 'portal_enabled' => true, 'portal_must_change_password' => true]);
        $this->actingAs($member, 'member');
        Auth::shouldUse('web');

        $this->get(route('portal.password.edit'))
            ->assertOk()
            ->assertDontSee('<nav class="portal-bottomnav"', false);
    }
}
