<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Services\PortfolioReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create();
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function member(array $attributes = []): Member
    {
        return Member::factory()->create(array_merge(['sacco_id' => $this->sacco->id, 'status' => 'active'], $attributes));
    }

    public function test_snapshot_reports_todays_savings_and_portfolio_at_risk(): void
    {
        $member = $this->member();

        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 50000, 'transaction_date' => Carbon::today()]);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 70000, 'transaction_date' => Carbon::today()->subDays(3)]);

        Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'total_amount' => 300000, 'term_months' => 6]);
        Loan::factory()->overdue()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'total_amount' => 100000]);

        $snapshot = app(PortfolioReportService::class)->snapshot();

        $this->assertEquals(50000.0, $snapshot['deposits_today']);
        $this->assertEquals(400000.0, $snapshot['outstanding_balance']);
        $this->assertEquals(100000.0, $snapshot['at_risk_balance']);
        $this->assertEquals(25.0, $snapshot['portfolio_at_risk_pct']);
        $this->assertSame(1, $snapshot['delinquent_loans']);
        $this->assertCount(1, $snapshot['at_risk_members']);
    }

    public function test_repayments_reduce_outstanding_balance(): void
    {
        $member = $this->member();
        $loan = Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'total_amount' => 200000]);
        Repayment::factory()->create(['sacco_id' => $this->sacco->id, 'loan_id' => $loan->id, 'member_id' => $member->id, 'amount' => 50000]);

        $this->assertEquals(150000.0, app(PortfolioReportService::class)->snapshot()['outstanding_balance']);
    }

    public function test_snapshot_with_no_loans_has_zero_par(): void
    {
        $snapshot = app(PortfolioReportService::class)->snapshot();

        $this->assertSame(0.0, $snapshot['portfolio_at_risk_pct']);
        $this->assertTrue($snapshot['at_risk_members']->isEmpty());
    }

    public function test_contribution_trend_buckets_by_month(): void
    {
        $member = $this->member();

        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 10000, 'transaction_date' => Carbon::today()]);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'withdrawal', 'amount' => 4000, 'transaction_date' => Carbon::today()]);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 99000, 'transaction_date' => Carbon::today()->subMonths(12)]);

        $trend = app(PortfolioReportService::class)->contributionTrend(6);

        $this->assertCount(6, $trend['labels']);
        $this->assertEquals(10000.0, $trend['deposits'][5]);
        $this->assertEquals(4000.0, $trend['withdrawals'][5]);
        $this->assertEquals(0.0, array_sum(array_slice($trend['deposits'], 0, 5)));
    }

    public function test_default_rate_trend_uses_null_for_empty_months(): void
    {
        $member = $this->member();
        $disbursed = Carbon::today()->subMonths(2)->startOfMonth()->addDays(3);

        Loan::factory()->defaulted()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'disbursed_at' => $disbursed]);
        Loan::factory()->closed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'disbursed_at' => $disbursed]);

        $trend = app(PortfolioReportService::class)->defaultRateTrend(6);

        $this->assertEquals(50.0, $trend['rates'][3]);
        $this->assertSame(2, $trend['disbursed'][3]);
        $this->assertNull($trend['rates'][5]);
    }

    public function test_top_risk_loans_are_ordered_by_score_and_exclude_closed(): void
    {
        $member = $this->member();

        Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'risk_score' => 30]);
        Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'risk_score' => 80]);
        Loan::factory()->closed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'risk_score' => 99]);

        $scores = app(PortfolioReportService::class)->topRiskLoans()->pluck('risk_score')->map(fn ($s) => (float) $s)->all();

        $this->assertSame([80.0, 30.0], $scores);
    }

    public function test_dashboard_renders_with_and_without_data(): void
    {
        $this->get(route('tenant.dashboard'))->assertOk()->assertSee('Portfolio at Risk');

        $member = $this->member();
        Loan::factory()->overdue()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'risk_score' => 70]);

        $this->get(route('tenant.dashboard'))->assertOk()->assertSee($member->full_name);
    }

    public function test_dashboard_figures_never_include_other_saccos(): void
    {
        $otherSacco = Sacco::factory()->create();
        $otherMember = Member::factory()->create(['sacco_id' => $otherSacco->id, 'status' => 'active']);
        Loan::factory()->overdue()->create(['sacco_id' => $otherSacco->id, 'member_id' => $otherMember->id, 'total_amount' => 900000]);
        Saving::factory()->create(['sacco_id' => $otherSacco->id, 'member_id' => $otherMember->id, 'type' => 'deposit', 'amount' => 777000, 'transaction_date' => Carbon::today()]);

        $snapshot = app(PortfolioReportService::class)->snapshot();

        $this->assertEquals(0.0, $snapshot['outstanding_balance']);
        $this->assertEquals(0.0, $snapshot['deposits_today']);
    }

    public function test_reports_are_restricted_to_sacco_admins(): void
    {
        $officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);

        $this->actingAs($officer)->get(route('tenant.reports.index'))->assertForbidden();
        $this->actingAs($officer)->get(route('tenant.reports.savings_csv'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('tenant.reports.index'))->assertOk();
    }

    public function test_savings_csv_export_is_scoped_filtered_and_formula_safe(): void
    {
        $member = $this->member(['full_name' => '=HYPERLINK("http://evil")']);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 25000, 'transaction_date' => Carbon::today(), 'transaction_reference' => 'SAV-MINE']);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'type' => 'deposit', 'amount' => 1000, 'transaction_date' => Carbon::today()->subYear(), 'transaction_reference' => 'SAV-OLD']);

        $otherSacco = Sacco::factory()->create();
        $otherMember = Member::factory()->create(['sacco_id' => $otherSacco->id, 'status' => 'active']);
        Saving::factory()->create(['sacco_id' => $otherSacco->id, 'member_id' => $otherMember->id, 'transaction_reference' => 'SAV-OTHER']);

        $response = $this->get(route('tenant.reports.savings_csv', ['start_date' => Carbon::today()->subMonth()->format('Y-m-d')]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('SAV-MINE', $csv);
        $this->assertStringNotContainsString('SAV-OLD', $csv);
        $this->assertStringNotContainsString('SAV-OTHER', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_loans_csv_export_filters_by_status_and_validates_input(): void
    {
        $member = $this->member();
        Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'loan_reference' => 'LN-PENDING-1']);
        Loan::factory()->overdue()->create(['sacco_id' => $this->sacco->id, 'member_id' => $member->id, 'loan_reference' => 'LN-OVERDUE-1']);

        $csv = $this->get(route('tenant.reports.loans_csv', ['status' => 'overdue']))->assertOk()->streamedContent();

        $this->assertStringContainsString('LN-OVERDUE-1', $csv);
        $this->assertStringNotContainsString('LN-PENDING-1', $csv);

        $this->get(route('tenant.reports.loans_csv', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }
}
