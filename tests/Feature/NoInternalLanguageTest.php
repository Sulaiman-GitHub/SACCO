<?php

namespace Tests\Feature;

use App\Models\ConsentChangeRequest;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nothing about how the system is built may appear on a screen real users see: module numbers,
 * file names, database field names, or internal terms. This renders every screen for each kind
 * of user and checks the visible text.
 */
class NoInternalLanguageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Patterns that must never appear in visible text.
     */
    private const FORBIDDEN = [
        '/\bModules? \d/i',
        '/Modules? (Roadmap|Progression)/i',
        '/Architecture/i',
        '/SYSTEM_ARCHITECTURE|CLAUDE\.md|\.md\b/',
        '/\bsacco_id\b|\bmember_id\b|\bloan_id\b|\btenure_months\b|\bscoring_consent\b/',
        '/microservice|heuristic|calibrated|synthetic|champion|logistic|\bAlgorithm\b/i',
        '/\bTenants?\b|\bmulti-?tenan/i',
        '/isolated|isolation/i',
        '/SHA-?256|cryptographic|digest/i',
        '/append-?only|immutable/i',
        '/rule engine/i',
        '/\bML\b/',
        '/\bDPPA\b/',
        '/Eloquent|Laravel|Python|FastAPI|query scoping/i',
        '/\b[a-z]+\.[a-z_]+ recorded\b|\bsavings\.recorded\b|\bloan\.(applied|approved|rejected|disbursed)\b|\bpolicy\.updated\b|\bconsent\.change_/',
    ];

    private Sacco $sacco;

    private User $admin;

    private User $officer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        // The scoring service is "down", so loan pages also show the wording used when it is unavailable.
        Http::fake(['*/score' => Http::failedConnection()]);

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->member = Member::factory()->consented($this->admin)->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
    }

    /**
     * What a person sees on the page: no scripts, styles, tags or hidden markup.
     */
    private function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<[^>]+>#', ' ', (string) $html);
        $text = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('#\s+#', ' ', $text);
    }

    /**
     * Tooltips and placeholders are visible to users too, so they are checked as well.
     */
    private function attributeText(string $html): string
    {
        preg_match_all('#\b(?:title|placeholder|aria-label|alt)="([^"]*)"#i', $html, $matches);

        return implode(' | ', $matches[1]);
    }

    private function assertCleanPage(string $path): void
    {
        $response = $this->get($path);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString('<!--', $html, "{$path} ships HTML comments to the browser.");

        $haystack = $this->visibleText($html).' | '.$this->attributeText($html);

        foreach (self::FORBIDDEN as $pattern) {
            $this->assertSame(0, preg_match($pattern, $haystack, $hit), "{$path} shows internal language matching {$pattern}: ".($hit[0] ?? ''));
        }
    }

    /**
     * Give the SACCO enough data that every card, table and state on the staff screens renders.
     */
    private function seedActivity(): Loan
    {
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => 300000]);
        $loan = Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'risk_score' => 22.5, 'decision' => 'approve', 'decision_reason' => 'Recommended for approval.']);
        Repayment::factory()->create(['sacco_id' => $this->sacco->id, 'loan_id' => $loan->id, 'member_id' => $this->member->id, 'amount' => 50000]);
        ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_SCORING, null);
        $this->member->issuePortalCredentials();

        return $loan;
    }

    public function test_every_loan_state_contains_no_internal_language(): void
    {
        $this->seedActivity();

        $states = [
            Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'pending', 'risk_score' => 61.0, 'decision' => 'require_guarantor', 'decision_reason' => 'Guarantor required by policy.']),
            Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'pending', 'risk_score' => 82.0, 'decision' => 'reject', 'decision_reason' => 'Recommended for rejection.']),
            Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'approved']),
            Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'status' => 'rejected', 'rejection_reason' => 'Insufficient savings.']),
            Loan::factory()->disbursed(now()->subMonths(8))->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'term_months' => 3, 'status' => 'overdue']),
            Loan::factory()->disbursed(now()->subMonths(12))->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'term_months' => 3, 'status' => 'defaulted']),
        ];

        $this->actingAs($this->admin);

        foreach ($states as $loan) {
            $this->assertCleanPage(route('tenant.loans.show', $loan));
        }

        $this->assertCleanPage(route('tenant.savings.show', Saving::firstOrFail()));
        $this->assertCleanPage(route('tenant.repayments.show', Repayment::firstOrFail()));
    }

    public function test_staff_screens_contain_no_internal_language(): void
    {
        $loan = $this->seedActivity();

        $this->actingAs($this->admin);

        foreach ([
            route('tenant.dashboard'),
            route('tenant.members.index'),
            route('tenant.members.create'),
            route('tenant.members.show', $this->member),
            route('tenant.members.edit', $this->member),
            route('tenant.members.savings_statement', $this->member),
            route('tenant.savings.index'),
            route('tenant.savings.create'),
            route('tenant.loans.index'),
            route('tenant.loans.create'),
            route('tenant.loans.show', $loan),
            route('tenant.repayments.index'),
            route('tenant.repayments.create'),
            route('tenant.staff.index'),
            route('tenant.staff.create'),
            route('tenant.reports.index'),
            route('tenant.audit_log.index'),
            route('tenant.lending_policy.edit'),
        ] as $url) {
            $this->assertCleanPage($url);
        }
    }

    public function test_a_loan_officers_screens_contain_no_internal_language(): void
    {
        $this->seedActivity();

        $this->actingAs($this->officer);

        foreach ([route('tenant.dashboard'), route('tenant.members.show', $this->member), route('tenant.loans.index')] as $url) {
            $this->assertCleanPage($url);
        }
    }

    public function test_a_member_with_no_loans_sees_a_plain_credit_assessment_message(): void
    {
        $this->actingAs($this->admin)->get(route('tenant.members.show', $this->member))
            ->assertOk()
            ->assertSee('Credit Risk Assessment')
            ->assertSee('A credit risk assessment will appear here once this member applies for a loan.');
    }

    public function test_the_dashboard_has_no_build_progress_section(): void
    {
        $this->actingAs($this->admin)->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertDontSee('Roadmap')
            ->assertDontSee('COMPLETED');
    }

    public function test_the_audit_log_uses_plain_words_for_actions_and_details(): void
    {
        $this->seedActivity();

        $this->actingAs($this->admin)->get(route('tenant.audit_log.index'))
            ->assertOk()
            ->assertSee('Savings transaction recorded')
            ->assertSee('Loan application submitted')
            ->assertSee('Repayment recorded')
            ->assertSee('Member consent recorded')
            ->assertSee('Member asked to change consent');
    }

    public function test_super_admin_and_login_screens_contain_no_internal_language(): void
    {
        $this->get(route('login'))->assertOk();
        $this->assertCleanPage(route('login'));
        $this->assertCleanPage(route('portal.login'));

        $superAdmin = User::factory()->create(['sacco_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($superAdmin);

        foreach ([
            route('super_admin.dashboard'),
            route('super_admin.saccos.index'),
            route('super_admin.saccos.create'),
            route('super_admin.saccos.show', $this->sacco),
        ] as $url) {
            $this->assertCleanPage($url);
        }
    }

    public function test_member_portal_screens_contain_no_internal_language(): void
    {
        $this->seedActivity();
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();
        $this->actingAs($this->member->fresh(), 'member');
        Auth::shouldUse('web');

        foreach ([route('portal.dashboard'), route('portal.savings'), route('portal.loans'), route('portal.consent'), route('portal.password.edit')] as $url) {
            $this->assertCleanPage($url);
        }
    }

    public function test_the_loan_page_describes_the_unavailable_scoring_service_in_plain_words(): void
    {
        $loan = $this->seedActivity();

        $this->actingAs($this->admin)->get(route('tenant.loans.show', $loan))
            ->assertOk()
            ->assertSee('Simplified estimate')
            ->assertSee('The credit scoring service was unavailable.')
            ->assertSee('Lending Policy Recommendation');
    }
}
