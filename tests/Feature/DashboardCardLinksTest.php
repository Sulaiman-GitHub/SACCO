<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardCardLinksTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create();
    }

    private function staff(string $role): User
    {
        return User::factory()->create([
            'sacco_id' => $this->sacco->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_dashboard_cards_link_to_the_pages_they_summarise(): void
    {
        $response = $this->actingAs($this->staff('sacco_admin'))->get(route('tenant.dashboard'));

        $response->assertOk();
        $response->assertSee(route('tenant.loans.index', ['status' => 'pending']), false);
        $response->assertSee(route('tenant.loans.index', ['status' => 'at_risk']), false);
        $response->assertSee(route('tenant.loans.index', ['status' => 'outstanding']), false);
        $response->assertSee(route('tenant.members.index'), false);
        $response->assertSee(route('tenant.savings.index'), false);
        $response->assertSee('<a class="stat-tile" href="'.route('tenant.staff.index').'"', false);
    }

    public function test_loan_officer_does_not_get_a_link_to_staff_management(): void
    {
        $response = $this->actingAs($this->staff('loan_officer'))->get(route('tenant.dashboard'));

        $response->assertOk();
        $response->assertDontSee('<a class="stat-tile" href="'.route('tenant.staff.index').'"', false);
    }

    public function test_awaiting_decision_card_count_matches_the_pending_list(): void
    {
        Loan::factory()->count(3)->create(['sacco_id' => $this->sacco->id, 'status' => 'pending']);
        Loan::factory()->count(2)->create(['sacco_id' => $this->sacco->id, 'status' => 'closed']);

        $user = $this->staff('loan_officer');

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();

        $list = $this->actingAs($user)->get(route('tenant.loans.index', ['status' => 'pending']));

        $list->assertOk();
        $this->assertSame(3, $list->viewData('loans')->total());
    }

    public function test_loan_status_groups_cover_the_statuses_the_cards_describe(): void
    {
        foreach (['pending', 'approved', 'disbursed', 'overdue', 'defaulted', 'closed', 'rejected'] as $status) {
            Loan::factory()->create(['sacco_id' => $this->sacco->id, 'status' => $status]);
        }

        $this->assertEqualsCanonicalizing(['disbursed', 'overdue'], Loan::status('active')->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['overdue', 'defaulted'], Loan::status('at_risk')->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['disbursed', 'overdue', 'defaulted'], Loan::status('outstanding')->pluck('status')->all());
        $this->assertSame(['pending'], Loan::status('pending')->pluck('status')->all());
    }

    public function test_deposits_today_card_opens_only_todays_deposits(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $today = now()->toDateString();

        Saving::factory()->count(2)->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'transaction_date' => $today]);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'withdrawal', 'transaction_date' => $today]);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'transaction_date' => now()->subDays(3)->toDateString()]);

        $user = $this->staff('loan_officer');
        $url = route('tenant.savings.index', ['type' => 'deposit', 'start_date' => $today, 'end_date' => $today]);

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertSee(e($url), false);

        $list = $this->actingAs($user)->get($url);

        $list->assertOk();
        $this->assertSame(2, $list->viewData('savings')->total());
    }
}
