<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConsentChangeRequest;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MemberPortalTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    private User $officer;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $this->officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->member = Member::factory()->consented($this->admin)->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
    }

    /**
     * Sign a member in on the `member` guard, leaving `web` as the default guard exactly as it is in
     * a real request (actingAs() with a guard would otherwise make the member the default user).
     */
    private function signInAsMember(Member $member): static
    {
        $this->actingAs($member, 'member');
        Auth::shouldUse('web');

        return $this;
    }

    /**
     * Switch portal access on for a member and return the temporary password.
     */
    private function enablePortal(Member $member): string
    {
        return $member->issuePortalCredentials();
    }

    /**
     * Sign a member in through the real login form and change the temporary password.
     */
    private function signInWithChosenPassword(Member $member, string $temporaryPassword): void
    {
        $this->post(route('portal.login.attempt'), [
            'portal_username' => $member->portal_username,
            'password' => $temporaryPassword,
        ])->assertRedirect();

        $this->put(route('portal.password.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'Chosen123pass',
            'password_confirmation' => 'Chosen123pass',
        ])->assertSessionHasNoErrors();
    }

    public function test_admin_enables_portal_access_and_sees_the_temporary_password_once(): void
    {
        $response = $this->actingAs($this->admin)->post(route('tenant.members.portal_credentials', $this->member));

        $response->assertSessionHas('portal_credentials');
        $credentials = session('portal_credentials');

        $member = $this->member->fresh();
        $this->assertTrue($member->portal_enabled);
        $this->assertTrue($member->portal_must_change_password);
        $this->assertSame($credentials['username'], $member->portal_username);
        $this->assertNotSame($credentials['password'], $member->password);
        $this->assertTrue(Hash::check($credentials['password'], $member->password));

        $entry = AuditLog::withoutTenantScope()->where('action', 'member.portal_credentials_issued')->firstOrFail();
        $this->assertStringNotContainsString($credentials['password'], (string) $entry->getRawOriginal('details'));
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);

        $this->get(route('tenant.members.show', $this->member))->assertOk()->assertSee($credentials['password']);
        $this->get(route('tenant.members.show', $this->member))->assertOk()->assertDontSee($credentials['password']);
    }

    public function test_only_a_sacco_admin_can_change_portal_access(): void
    {
        $this->actingAs($this->officer)->post(route('tenant.members.portal_credentials', $this->member))->assertForbidden();
        $this->post(route('tenant.members.portal_disable', $this->member))->assertForbidden();

        $this->assertFalse($this->member->fresh()->portal_enabled);
    }

    public function test_another_saccos_admin_cannot_change_portal_access(): void
    {
        $otherAdmin = User::factory()->create(['sacco_id' => Sacco::factory()->create()->id, 'role' => 'sacco_admin', 'status' => 'active']);

        $this->actingAs($otherAdmin)->post(route('tenant.members.portal_credentials', $this->member))->assertNotFound();

        $this->assertFalse($this->member->fresh()->portal_enabled);
    }

    public function test_inactive_members_cannot_be_given_portal_access(): void
    {
        $this->member->update(['status' => 'inactive']);

        $this->actingAs($this->admin)->post(route('tenant.members.portal_credentials', $this->member))
            ->assertSessionHas('error');

        $this->assertFalse($this->member->fresh()->portal_enabled);
    }

    public function test_portal_usernames_are_unique_across_saccos(): void
    {
        $otherMember = Member::factory()->create(['sacco_id' => Sacco::factory()->create()->id, 'member_number' => $this->member->member_number]);

        $this->enablePortal($this->member);
        $this->enablePortal($otherMember);

        $this->assertNotSame($this->member->fresh()->portal_username, $otherMember->fresh()->portal_username);
    }

    public function test_member_must_choose_a_new_password_at_first_sign_in(): void
    {
        $temporary = $this->enablePortal($this->member);

        $this->post(route('portal.login.attempt'), [
            'portal_username' => $this->member->portal_username,
            'password' => $temporary,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->member->fresh(), 'member');
        $this->assertNotNull($this->member->fresh()->portal_last_login_at);

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.password.edit'));
        $this->get(route('portal.savings'))->assertRedirect(route('portal.password.edit'));
        $this->get(route('portal.password.edit'))->assertOk();

        $this->put(route('portal.password.update'), [
            'current_password' => $temporary,
            'password' => 'Chosen123pass',
            'password_confirmation' => 'Chosen123pass',
        ])->assertRedirect(route('portal.dashboard'));

        $this->assertFalse($this->member->fresh()->portal_must_change_password);
        $this->assertTrue(Hash::check('Chosen123pass', $this->member->fresh()->password));
        $this->get(route('portal.dashboard'))->assertOk();
    }

    public function test_new_password_must_be_strong_and_different(): void
    {
        $temporary = $this->enablePortal($this->member);
        $this->signInAsMember($this->member->fresh());

        $this->put(route('portal.password.update'), [
            'current_password' => $temporary,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->put(route('portal.password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'Chosen123pass',
            'password_confirmation' => 'Chosen123pass',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue($this->member->fresh()->portal_must_change_password);
    }

    public function test_wrong_credentials_get_one_generic_message_and_repeated_failures_are_throttled(): void
    {
        $this->enablePortal($this->member);

        $message = 'The login name or password is not correct, or portal access is not available. Please contact your SACCO.';

        $this->post(route('portal.login.attempt'), ['portal_username' => 'nobody', 'password' => 'whatever1'])
            ->assertSessionHasErrors(['portal_username' => $message]);
        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->portal_username, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['portal_username' => $message]);
        $this->assertGuest('member');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->portal_username, 'password' => 'wrong-password']);
        }

        $temporary = $this->enablePortal($this->member);
        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->portal_username, 'password' => $temporary])
            ->assertSessionHasErrors('portal_username');
        $this->assertGuest('member');
    }

    public function test_members_without_portal_access_cannot_sign_in(): void
    {
        $temporary = $this->enablePortal($this->member);
        $this->member->disablePortal();

        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->portal_username, 'password' => $temporary])
            ->assertSessionHasErrors('portal_username');

        $this->assertGuest('member');
    }

    public function test_inactive_members_and_inactive_saccos_cannot_sign_in_and_lose_open_sessions(): void
    {
        $temporary = $this->enablePortal($this->member);
        $this->member->update(['status' => 'inactive']);

        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->portal_username, 'password' => $temporary])
            ->assertSessionHasErrors('portal_username');
        $this->assertGuest('member');

        $this->member->update(['status' => 'active']);
        $this->member->forceFill(['portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member->fresh());
        $this->get(route('portal.dashboard'))->assertOk();

        $this->sacco->update(['status' => 'suspended']);
        $this->signInAsMember($this->member->fresh())->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
        $this->assertGuest('member');
    }

    public function test_switching_portal_access_off_ends_an_open_session(): void
    {
        $this->enablePortal($this->member);
        $this->member->forceFill(['portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member->fresh());
        $this->get(route('portal.dashboard'))->assertOk();

        $this->actingAs($this->admin)->post(route('tenant.members.portal_disable', $this->member))->assertSessionHas('success');
        $this->signInAsMember($this->member->fresh())->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    public function test_resetting_the_password_invalidates_the_old_one(): void
    {
        $first = $this->enablePortal($this->member);
        $second = $this->enablePortal($this->member->fresh());

        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->fresh()->portal_username, 'password' => $first])
            ->assertSessionHasErrors('portal_username');
        $this->assertGuest('member');

        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->fresh()->portal_username, 'password' => $second])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated('member');
    }

    public function test_member_sees_their_own_savings_loans_and_repayments(): void
    {
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => 250000, 'transaction_reference' => 'SAV-OWN-1']);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'withdrawal', 'amount' => 50000, 'transaction_reference' => 'SAV-OWN-2']);
        $loan = Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'loan_reference' => 'LN-OWN-1']);
        Repayment::factory()->create(['sacco_id' => $this->sacco->id, 'loan_id' => $loan->id, 'member_id' => $this->member->id, 'amount' => 100000, 'repayment_reference' => 'REP-OWN-1']);

        $this->signInAsMember($this->member);
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();

        $this->get(route('portal.dashboard'))->assertOk()->assertSee('200,000.00');
        $this->get(route('portal.savings'))->assertOk()->assertSee('SAV-OWN-1')->assertSee('SAV-OWN-2');
        $this->get(route('portal.loans'))->assertOk()->assertSee('LN-OWN-1')->assertSee('REP-OWN-1');
        $this->get(route('portal.consent'))->assertOk()->assertSee('Agreed');
    }

    public function test_member_never_sees_other_members_data_even_in_the_same_sacco_or_another_sacco(): void
    {
        $neighbour = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $outsider = Member::factory()->create(['sacco_id' => Sacco::factory()->create()->id]);

        foreach ([$neighbour, $outsider] as $other) {
            Saving::factory()->create(['sacco_id' => $other->sacco_id, 'member_id' => $other->id, 'type' => 'deposit', 'transaction_reference' => 'SAV-OTHER-'.$other->id]);
            Loan::factory()->create(['sacco_id' => $other->sacco_id, 'member_id' => $other->id, 'loan_reference' => 'LN-OTHER-'.$other->id]);
            ConsentChangeRequest::factory()->create(['member_id' => $other->id, 'member_note' => 'NOTE-OTHER-'.$other->id]);
        }

        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member);

        foreach (['portal.dashboard', 'portal.savings', 'portal.loans', 'portal.consent'] as $routeName) {
            $this->get(route($routeName))->assertOk()
                ->assertDontSee('OTHER-'.$neighbour->id)
                ->assertDontSee('OTHER-'.$outsider->id)
                ->assertDontSee($neighbour->full_name)
                ->assertDontSee($outsider->full_name);
        }
    }

    public function test_risk_score_and_rule_engine_decision_are_not_shown_to_members(): void
    {
        Loan::factory()->create([
            'sacco_id' => $this->sacco->id,
            'member_id' => $this->member->id,
            'risk_score' => 77.5,
            'decision' => 'reject',
            'decision_reason' => 'SECRET-DECISION-REASON',
            'notes' => 'SECRET-INTERNAL-NOTE',
            'guarantor_name' => 'SECRET-GUARANTOR',
        ]);

        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();

        $this->signInAsMember($this->member)->get(route('portal.loans'))->assertOk()
            ->assertDontSee('77.5')
            ->assertDontSee('SECRET-DECISION-REASON')
            ->assertDontSee('SECRET-INTERNAL-NOTE')
            ->assertDontSee('SECRET-GUARANTOR');
    }

    public function test_portal_is_read_only_apart_from_login_password_and_consent_requests(): void
    {
        $allowedWrites = [
            'portal.login.attempt',
            'portal.logout',
            'portal.password.update',
            'portal.consent_requests.store',
        ];

        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'portal.'))
            ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($route) => $route->getName())
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($allowedWrites, $writeRoutes);
    }

    public function test_member_session_cannot_reach_staff_routes_or_make_financial_changes(): void
    {
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member);

        $this->get(route('tenant.dashboard'))->assertRedirect(route('login'));
        $this->get(route('tenant.members.index'))->assertRedirect(route('login'));
        $this->get(route('tenant.loans.index'))->assertRedirect(route('login'));

        $this->post(route('tenant.savings.store'), [
            'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => 1000,
            'transaction_date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertRedirect(route('login'));
        $this->post(route('tenant.loans.store'), ['member_id' => $this->member->id, 'principal' => 100000])->assertRedirect(route('login'));
        $this->post(route('tenant.members.update_consent', $this->member), ['consent_data_processing' => '1', 'scoring_consent' => '0'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Saving::withoutTenantScope()->count());
        $this->assertSame(0, Loan::withoutTenantScope()->count());
        $this->assertTrue($this->member->fresh()->canBeScored());
        $this->assertGuest('web');
    }

    public function test_staff_session_cannot_use_the_portal(): void
    {
        $this->actingAs($this->admin)->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
        $this->get(route('portal.savings'))->assertRedirect(route('portal.login'));
    }

    public function test_portal_pages_require_a_signed_in_member(): void
    {
        foreach (['portal.dashboard', 'portal.savings', 'portal.loans', 'portal.consent', 'portal.password.edit'] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('portal.login'));
        }
        $this->post(route('portal.consent_requests.store'), ['request_type' => 'withdraw_scoring'])->assertRedirect(route('portal.login'));
    }

    public function test_signing_out_of_the_portal_keeps_a_staff_session_in_the_same_browser(): void
    {
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();

        $this->actingAs($this->admin)->signInAsMember($this->member);
        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

        $this->assertGuest('member');
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_member_can_request_a_consent_change_without_changing_the_consent_itself(): void
    {
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member);

        $this->post(route('portal.consent_requests.store'), [
            'request_type' => ConsentChangeRequest::TYPE_WITHDRAW_SCORING,
            'member_note' => 'Please stop scoring me.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('portal.consent'));

        $request = ConsentChangeRequest::withoutTenantScope()->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->member->id, $request->member_id);
        $this->assertSame($this->sacco->id, $request->sacco_id);

        $this->assertTrue($this->member->fresh()->scoring_consent);
        $this->assertTrue($this->member->fresh()->canBeScored());

        $entry = AuditLog::withoutTenantScope()->where('action', 'consent.change_requested')->firstOrFail();
        $this->assertSame($this->sacco->id, $entry->sacco_id);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);

        $this->get(route('portal.consent'))->assertOk()->assertSee('Waiting');
    }

    public function test_duplicate_or_unavailable_consent_requests_are_refused(): void
    {
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();
        $this->signInAsMember($this->member);

        $this->post(route('portal.consent_requests.store'), ['request_type' => ConsentChangeRequest::TYPE_WITHDRAW_SCORING])->assertSessionHasNoErrors();
        $this->post(route('portal.consent_requests.store'), ['request_type' => ConsentChangeRequest::TYPE_WITHDRAW_SCORING])->assertSessionHasErrors('request_type');
        $this->post(route('portal.consent_requests.store'), ['request_type' => ConsentChangeRequest::TYPE_GRANT_SCORING])->assertSessionHasErrors('request_type');
        $this->post(route('portal.consent_requests.store'), ['request_type' => 'delete_everything'])->assertSessionHasErrors('request_type');

        $this->assertSame(1, ConsentChangeRequest::withoutTenantScope()->count());
    }

    public function test_a_member_who_declined_scoring_can_ask_to_allow_it(): void
    {
        $this->member->recordConsent($this->admin, false, true);
        $this->member->forceFill(['portal_enabled' => true, 'portal_must_change_password' => false])->save();

        $this->signInAsMember($this->member->fresh())
            ->post(route('portal.consent_requests.store'), ['request_type' => ConsentChangeRequest::TYPE_GRANT_SCORING])
            ->assertSessionHasNoErrors();

        $this->post(route('portal.consent_requests.store'), ['request_type' => ConsentChangeRequest::TYPE_WITHDRAW_SCORING])
            ->assertSessionHasErrors('request_type');
        $this->assertFalse($this->member->fresh()->canBeScored());
    }

    public function test_staff_see_and_process_consent_requests(): void
    {
        $request = ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_DATA_PROCESSING, 'I am leaving.');

        $this->actingAs($this->officer)->get(route('tenant.members.show', $this->member))
            ->assertOk()->assertSee('Consent Requests From Member')->assertSee('I am leaving.');

        $this->post(route('tenant.members.consent_requests.resolve', [$this->member, $request]), ['outcome' => 'processed', 'handler_note' => 'Member exited.'])
            ->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('processed', $request->status);
        $this->assertSame($this->officer->id, $request->handled_by);
        $this->assertNotNull($request->handled_at);
        $this->assertNotNull(AuditLog::withoutTenantScope()->where('action', 'consent.change_request_processed')->first());

        $this->post(route('tenant.members.consent_requests.resolve', [$this->member, $request]), ['outcome' => 'dismissed', 'handler_note' => 'Too late.'])
            ->assertSessionHas('error');
        $this->assertSame('processed', $request->fresh()->status);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_dismissing_a_request_needs_a_reason(): void
    {
        $request = ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_SCORING, null);

        $this->actingAs($this->officer)
            ->post(route('tenant.members.consent_requests.resolve', [$this->member, $request]), ['outcome' => 'dismissed'])
            ->assertSessionHasErrors('handler_note');

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_other_saccos_staff_cannot_resolve_a_consent_request(): void
    {
        $request = ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_SCORING, null);
        $otherOfficer = User::factory()->create(['sacco_id' => Sacco::factory()->create()->id, 'role' => 'loan_officer', 'status' => 'active']);

        $this->actingAs($otherOfficer)
            ->post(route('tenant.members.consent_requests.resolve', [$this->member, $request]), ['outcome' => 'processed'])
            ->assertNotFound();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_request_cannot_be_resolved_through_a_different_members_url(): void
    {
        $request = ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_SCORING, null);
        $otherMember = Member::factory()->create(['sacco_id' => $this->sacco->id]);

        $this->actingAs($this->officer)
            ->post(route('tenant.members.consent_requests.resolve', [$otherMember, $request]), ['outcome' => 'processed'])
            ->assertNotFound();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_portal_credentials_cannot_be_set_through_member_registration_or_update(): void
    {
        $this->actingAs($this->officer)->put(route('tenant.members.update', $this->member), [
            'member_number' => $this->member->member_number,
            'full_name' => $this->member->full_name,
            'phone' => $this->member->phone,
            'join_date' => '2025-01-01',
            'portal_enabled' => '1',
            'portal_username' => 'hacker',
            'password' => 'Hacked123pass',
        ])->assertSessionHasNoErrors();

        $member = $this->member->fresh();
        $this->assertFalse($member->portal_enabled);
        $this->assertNull($member->portal_username);
        $this->assertNull($member->password);
    }

    public function test_audit_log_page_still_renders_with_portal_entries(): void
    {
        $this->enablePortal($this->member);
        ConsentChangeRequest::openFor($this->member, ConsentChangeRequest::TYPE_WITHDRAW_SCORING, null);

        $this->actingAs($this->admin)->get(route('tenant.audit_log.index'))->assertOk();
    }

    public function test_full_member_journey_through_the_login_form(): void
    {
        $temporary = $this->enablePortal($this->member);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'transaction_reference' => 'SAV-JOURNEY']);

        $this->signInWithChosenPassword($this->member->fresh(), $temporary);

        $this->get(route('portal.savings'))->assertOk()->assertSee('SAV-JOURNEY');
        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));
        $this->get(route('portal.savings'))->assertRedirect(route('portal.login'));

        $this->post(route('portal.login.attempt'), ['portal_username' => $this->member->fresh()->portal_username, 'password' => 'Chosen123pass'])
            ->assertRedirect();
        $this->assertAuthenticated('member');
    }
}
