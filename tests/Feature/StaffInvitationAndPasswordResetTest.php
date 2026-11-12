<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Sacco;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Notifications\StaffPasswordResetNotification;
use App\Services\AuditTrail;
use App\Services\StaffInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Email invitations for new staff and "forgot password" for existing staff. There is no self sign-up:
 * every account is created by an administrator.
 */
class StaffInvitationAndPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRAL = 'If that email address belongs to an active staff account, a password reset link has been sent. The link works for 60 minutes.';

    private Sacco $sacco;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create(['status' => 'active', 'name' => 'Kampala Teachers SACCO']);
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
    }

    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sarah Namubiru',
            'email' => 'sarah@sacco.test',
            'role' => 'loan_officer',
            'phone' => '+256 700 123456',
        ], $overrides);
    }

    /**
     * Create staff through the admin form and return the member plus the emailed token.
     *
     * @return array{0: User, 1: string}
     */
    private function inviteStaff(array $overrides = []): array
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('tenant.staff.store'), $this->staffPayload($overrides))->assertRedirect(route('tenant.staff.index'));

        $user = User::withoutTenantScope()->where('email', $overrides['email'] ?? 'sarah@sacco.test')->firstOrFail();
        $token = null;

        Notification::assertSentTo($user, StaffInvitationNotification::class, function (StaffInvitationNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        auth()->logout();

        return [$user, $token];
    }

    private function activeStaff(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'sacco_id' => $this->sacco->id,
            'role' => 'loan_officer',
            'status' => 'active',
        ], $attributes));
    }

    private function auditActions(): array
    {
        return AuditLog::withoutTenantScope()->pluck('action')->all();
    }

    // ---- Invitations ----------------------------------------------------------------------------------------

    public function test_creating_staff_sends_one_invitation_and_stores_only_the_hash(): void
    {
        [$user, $token] = $this->inviteStaff();

        Notification::assertSentToTimes($user, StaffInvitationNotification::class, 1);
        Notification::assertCount(1);

        $invitation = StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame($this->sacco->id, $invitation->sacco_id);
        $this->assertSame($this->admin->id, $invitation->created_by);
        $this->assertEqualsWithDelta(48 * 3600, $invitation->expires_at->diffInSeconds(now(), true), 5);
        $this->assertNull($invitation->used_at);

        // The token is nowhere in the database, and the audit entry carries no secret.
        $this->assertDatabaseMissing('staff_invitations', ['token_hash' => $token]);
        $audit = AuditLog::withoutTenantScope()->where('action', 'staff.invited')->sole();
        $this->assertStringNotContainsString($token, $audit->getRawOriginal('details'));
        $this->assertSame($user->id, (int) $audit->entity_id);
        $this->assertSame($this->sacco->id, (int) $audit->sacco_id);
    }

    public function test_the_invitation_email_has_the_right_subject_link_and_no_secrets(): void
    {
        [$user, $token] = $this->inviteStaff();

        $mail = (new StaffInvitationNotification($token, $this->sacco, 48))->toMail($user);
        $html = $mail->render();

        $this->assertSame('You have been invited to Kampala Teachers SACCO on SACCO Credit', $mail->subject);
        $this->assertStringContainsString(route('staff.invitation.show', $token), $html);
        $this->assertStringNotContainsString('password:', strtolower($html));
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_an_invited_account_cannot_be_signed_into_until_the_invitation_is_accepted(): void
    {
        [$user] = $this->inviteStaff();

        $this->assertNull($user->email_verified_at);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_valid_link_sets_the_password_verifies_the_email_and_signs_the_person_in(): void
    {
        [$user, $token] = $this->inviteStaff();

        $this->get(route('staff.invitation.show', $token))->assertOk()->assertSee('Activate My Account');

        $this->post(route('staff.invitation.accept', $token), [
            'password' => 'a-strong-passphrase',
            'password_confirmation' => 'a-strong-passphrase',
        ])->assertRedirect(route('tenant.dashboard'));

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a-strong-passphrase', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->sole()->used_at);

        $accepted = AuditLog::withoutTenantScope()->where('action', 'staff.invitation_accepted')->sole();
        $this->assertSame($user->id, (int) $accepted->user_id);
        $this->assertStringNotContainsString('a-strong-passphrase', $accepted->getRawOriginal('details'));
    }

    public function test_the_link_cannot_be_used_twice(): void
    {
        [$user, $token] = $this->inviteStaff();
        $payload = ['password' => 'a-strong-passphrase', 'password_confirmation' => 'a-strong-passphrase'];

        $this->post(route('staff.invitation.accept', $token), $payload)->assertRedirect();
        auth()->logout();

        $this->get(route('staff.invitation.show', $token))->assertStatus(410);
        $this->post(route('staff.invitation.accept', $token), ['password' => 'another-passphrase', 'password_confirmation' => 'another-passphrase'])->assertStatus(410);

        $this->assertTrue(Hash::check('a-strong-passphrase', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_an_expired_link_is_rejected(): void
    {
        [$user, $token] = $this->inviteStaff();

        $this->travelTo(now()->addHours(49));

        $this->get(route('staff.invitation.show', $token))->assertStatus(410);
        $this->post(route('staff.invitation.accept', $token), ['password' => 'a-strong-passphrase', 'password_confirmation' => 'a-strong-passphrase'])->assertStatus(410);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_an_unknown_or_tampered_link_is_rejected(): void
    {
        [, $token] = $this->inviteStaff();

        $this->get(route('staff.invitation.show', 'not-a-real-token'))->assertStatus(410);
        $this->get(route('staff.invitation.show', substr($token, 0, -1).'x'))->assertStatus(410);
    }

    public function test_the_new_password_must_be_at_least_ten_characters_and_confirmed(): void
    {
        [$user, $token] = $this->inviteStaff();

        $this->post(route('staff.invitation.accept', $token), ['password' => 'short-pw', 'password_confirmation' => 'short-pw'])
            ->assertSessionHasErrors('password');
        $this->post(route('staff.invitation.accept', $token), ['password' => 'a-strong-passphrase', 'password_confirmation' => 'different-passphrase'])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->get(route('staff.invitation.show', $token))->assertOk();
    }

    public function test_a_link_for_a_deactivated_member_or_suspended_sacco_stops_working(): void
    {
        [$user, $token] = $this->inviteStaff();

        $user->update(['status' => 'inactive']);
        $this->get(route('staff.invitation.show', $token))->assertStatus(410);

        $user->update(['status' => 'active']);
        $this->get(route('staff.invitation.show', $token))->assertOk();

        $this->sacco->update(['status' => 'suspended']);
        $this->get(route('staff.invitation.show', $token))->assertStatus(410);
    }

    public function test_resend_invalidates_the_older_link_and_sends_a_new_one(): void
    {
        [$user, $oldToken] = $this->inviteStaff();

        Notification::fake();
        $this->actingAs($this->admin)->post(route('tenant.staff.resend_invitation', $user))->assertSessionHas('success');

        $newToken = null;
        Notification::assertSentTo($user, StaffInvitationNotification::class, function (StaffInvitationNotification $n) use (&$newToken) {
            $newToken = $n->token;

            return true;
        });

        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(1, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->count());
        $this->assertContains('staff.invitation_resent', $this->auditActions());

        auth()->logout();
        $this->get(route('staff.invitation.show', $oldToken))->assertStatus(410);
        $this->get(route('staff.invitation.show', $newToken))->assertOk();
    }

    public function test_resending_is_limited_to_three_times_an_hour(): void
    {
        [$user] = $this->inviteStaff();
        Notification::fake();
        $this->actingAs($this->admin);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('tenant.staff.resend_invitation', $user))->assertSessionHas('success');
        }

        $this->post(route('tenant.staff.resend_invitation', $user))->assertSessionHas('error');
        Notification::assertSentToTimes($user, StaffInvitationNotification::class, 3);

        $this->travelTo(now()->addMinutes(61));
        $this->post(route('tenant.staff.resend_invitation', $user))->assertSessionHas('success');
    }

    public function test_resend_is_refused_for_a_member_who_has_already_set_a_password(): void
    {
        Notification::fake();
        $active = $this->activeStaff();

        $this->actingAs($this->admin)->post(route('tenant.staff.resend_invitation', $active))->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_an_administrator_cannot_invite_or_resend_for_another_saccos_staff(): void
    {
        $otherSacco = Sacco::factory()->create(['status' => 'active']);
        $outsider = User::factory()->unverified()->create(['sacco_id' => $otherSacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        app(StaffInvitations::class)->issue($outsider);
        Notification::fake();

        $this->actingAs($this->admin);
        $this->post(route('tenant.staff.resend_invitation', $outsider))->assertNotFound();
        $this->get(route('tenant.staff.temporary_password.edit', $outsider))->assertNotFound();
        $this->post(route('tenant.staff.temporary_password.update', $outsider), ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertNotFound();

        Notification::assertNothingSent();
        $this->assertSame(1, StaffInvitation::withoutTenantScope()->where('user_id', $outsider->id)->count());
        $this->assertFalse(Hash::check('new-password-1', $outsider->fresh()->password));
    }

    public function test_a_newly_invited_member_is_always_created_in_the_admins_own_sacco(): void
    {
        $otherSacco = Sacco::factory()->create(['status' => 'active']);
        Notification::fake();

        $this->actingAs($this->admin)->post(route('tenant.staff.store'), $this->staffPayload(['sacco_id' => $otherSacco->id]));

        $user = User::withoutTenantScope()->where('email', 'sarah@sacco.test')->sole();
        $this->assertSame($this->sacco->id, $user->sacco_id);
        $this->assertSame($this->sacco->id, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->sole()->sacco_id);
    }

    public function test_a_mail_failure_keeps_the_account_and_tells_the_admin_what_to_do(): void
    {
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP is down'));

        $this->actingAs($this->admin)->post(route('tenant.staff.store'), $this->staffPayload())
            ->assertRedirect(route('tenant.staff.index'))
            ->assertSessionHas('error', 'Account created but the email could not be sent. Use Resend or set a temporary password.');

        $user = User::withoutTenantScope()->where('email', 'sarah@sacco.test')->first();
        $this->assertNotNull($user, 'The account must not be rolled back when mail fails.');
        $this->assertSame(1, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->count());
    }

    public function test_the_staff_list_shows_invited_then_active_and_the_fallback_actions(): void
    {
        [$user, $token] = $this->inviteStaff();

        $this->actingAs($this->admin)->get(route('tenant.staff.index'))
            ->assertOk()
            ->assertSee('Invited')
            ->assertSee('Resend invitation')
            ->assertSee('Temporary password')
            ->assertDontSee($token);

        auth()->logout();
        $this->post(route('staff.invitation.accept', $token), ['password' => 'a-strong-passphrase', 'password_confirmation' => 'a-strong-passphrase']);
        auth()->logout();

        $this->actingAs($this->admin)->get(route('tenant.staff.index'))->assertOk()->assertDontSee('Resend invitation');

        $this->travelTo(now()->addDays(3));
        $pending = $this->activeStaff(['email_verified_at' => null]);
        app(StaffInvitations::class)->issue($pending);
        $this->travelTo(now()->addDays(3));
        $this->get(route('tenant.staff.index'))->assertSee('Invitation expired');
    }

    // ---- Fallback: temporary password -----------------------------------------------------------------------

    public function test_typing_a_password_when_adding_staff_still_works_and_sends_no_email(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('tenant.staff.store'), $this->staffPayload([
            'password' => 'temp-pass-123',
            'password_confirmation' => 'temp-pass-123',
        ]))->assertRedirect(route('tenant.staff.index'))->assertSessionHas('success');

        Notification::assertNothingSent();
        $user = User::withoutTenantScope()->where('email', 'sarah@sacco.test')->sole();
        $this->assertSame(0, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->count());

        auth()->logout();
        $this->post(route('login'), ['email' => 'sarah@sacco.test', 'password' => 'temp-pass-123'])->assertRedirect(route('tenant.dashboard'));
    }

    public function test_an_admin_can_set_a_temporary_password_which_cancels_a_pending_invitation(): void
    {
        [$user, $token] = $this->inviteStaff();

        $this->actingAs($this->admin)->get(route('tenant.staff.temporary_password.edit', $user))->assertOk();
        $this->post(route('tenant.staff.temporary_password.update', $user), ['password' => 'temp-pass-123', 'password_confirmation' => 'temp-pass-123'])
            ->assertRedirect(route('tenant.staff.index'));

        $this->assertTrue(Hash::check('temp-pass-123', $user->fresh()->password));
        $this->assertSame(0, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->count());
        $this->assertContains('staff.temporary_password_set', $this->auditActions());

        auth()->logout();
        $this->get(route('staff.invitation.show', $token))->assertStatus(410);
    }

    public function test_an_admin_cannot_set_their_own_temporary_password(): void
    {
        $this->actingAs($this->admin)->post(route('tenant.staff.temporary_password.update', $this->admin), ['password' => 'temp-pass-123', 'password_confirmation' => 'temp-pass-123'])
            ->assertSessionHas('error');

        $this->assertFalse(Hash::check('temp-pass-123', $this->admin->fresh()->password));
    }

    public function test_a_super_admin_onboarding_a_sacco_without_a_password_sends_an_invitation(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'sacco_id' => null]);

        $this->actingAs($superAdmin)->post(route('super_admin.saccos.store'), [
            'name' => 'Gulu Community SACCO',
            'tier' => 'Tier 4',
            'admin_name' => 'Moses Okot',
            'admin_email' => 'okot@gulu.ug',
        ])->assertRedirect(route('super_admin.saccos.index'));

        $admin = User::withoutTenantScope()->where('email', 'okot@gulu.ug')->sole();
        Notification::assertSentTo($admin, StaffInvitationNotification::class);
        $this->assertSame($admin->sacco_id, StaffInvitation::withoutTenantScope()->where('user_id', $admin->id)->sole()->sacco_id);
    }

    // ---- Forgot password ------------------------------------------------------------------------------------

    public function test_the_staff_sign_in_page_links_to_forgot_password_but_the_member_portal_does_not(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Forgot your password?')->assertSee(route('password.request'), false);
        $this->get(route('portal.login'))->assertOk()->assertDontSee('Forgot your password?')->assertDontSee('forgot-password', false);
    }

    public function test_forgot_password_answers_the_same_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $staff = $this->activeStaff();

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $staff->email]);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@nowhere.test']);

        $known->assertRedirect(route('password.request'))->assertSessionHas('status', self::NEUTRAL)->assertSessionHasNoErrors();
        $unknown->assertRedirect(route('password.request'))->assertSessionHas('status', self::NEUTRAL)->assertSessionHasNoErrors();

        Notification::assertSentToTimes($staff, StaffPasswordResetNotification::class, 1);
        Notification::assertCount(1);
        $this->assertContains('staff.password_reset_requested', $this->auditActions());
    }

    public function test_a_deactivated_member_or_member_of_a_suspended_sacco_gets_no_reset_email(): void
    {
        Notification::fake();
        $inactive = $this->activeStaff(['status' => 'inactive']);
        $suspendedSacco = Sacco::factory()->create(['status' => 'suspended']);
        $ofSuspended = User::factory()->create(['sacco_id' => $suspendedSacco->id, 'role' => 'sacco_admin', 'status' => 'active']);

        foreach ([$inactive, $ofSuspended] as $user) {
            $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status', self::NEUTRAL);
        }

        Notification::assertNothingSent();
    }

    public function test_a_super_admin_has_no_email_reset(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'sacco_id' => null]);

        $this->post(route('password.email'), ['email' => $superAdmin->email])->assertSessionHas('status', self::NEUTRAL);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_is_limited_to_five_requests_an_hour_per_email_and_address(): void
    {
        Notification::fake();
        $staff = $this->activeStaff();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => $staff->email])->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => $staff->email])->assertSessionHasErrors('email');
        // Same limit for an address with no account, so the limit reveals nothing.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => 'ghost@nowhere.test']);
        }
        $this->post(route('password.email'), ['email' => 'ghost@nowhere.test'])->assertSessionHasErrors('email');

        $this->post(route('password.email'), ['email' => $this->activeStaff()->email])->assertSessionHasNoErrors();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function requestReset(User $user): array
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, StaffPasswordResetNotification::class, function (StaffPasswordResetNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return [$user, $token];
    }

    public function test_a_reset_link_changes_the_password_once(): void
    {
        [$user, $token] = $this->requestReset($this->activeStaff());

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Choose a new password');

        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-passphrase', 'password_confirmation' => 'brand-new-passphrase'];
        $this->post(route('password.update', $token), $payload)->assertRedirect(route('login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-passphrase', $user->fresh()->password));
        $this->assertContains('staff.password_reset_completed', $this->auditActions());

        $second = array_merge($payload, ['password' => 'yet-another-passphrase', 'password_confirmation' => 'yet-another-passphrase']);
        $this->post(route('password.update', $token), $second)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('brand-new-passphrase', $user->fresh()->password));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'brand-new-passphrase'])->assertRedirect(route('tenant.dashboard'));
    }

    public function test_a_reset_link_expires_after_sixty_minutes(): void
    {
        [$user, $token] = $this->requestReset($this->activeStaff());
        $original = $user->password;

        $this->travelTo(now()->addMinutes(61));

        $this->post(route('password.update', $token), ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-passphrase', 'password_confirmation' => 'brand-new-passphrase'])
            ->assertSessionHasErrors('email');
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_a_reset_link_stops_working_if_the_member_is_deactivated_meanwhile(): void
    {
        [$user, $token] = $this->requestReset($this->activeStaff());
        $user->update(['status' => 'inactive']);

        $this->post(route('password.update', $token), ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-passphrase', 'password_confirmation' => 'brand-new-passphrase'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('brand-new-passphrase', $user->fresh()->password));
    }

    public function test_resetting_a_password_retires_a_pending_invitation_and_verifies_the_address(): void
    {
        [$user, $inviteToken] = $this->inviteStaff();
        [, $resetToken] = $this->requestReset($user);

        $this->post(route('password.update', $resetToken), ['token' => $resetToken, 'email' => $user->email, 'password' => 'brand-new-passphrase', 'password_confirmation' => 'brand-new-passphrase'])
            ->assertRedirect(route('login'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(0, StaffInvitation::withoutTenantScope()->where('user_id', $user->id)->count());
        $this->get(route('staff.invitation.show', $inviteToken))->assertStatus(410);
    }

    public function test_audit_entries_for_these_flows_never_contain_tokens_or_passwords(): void
    {
        [$user, $inviteToken] = $this->inviteStaff();
        [, $resetToken] = $this->requestReset($user);
        $this->post(route('password.update', $resetToken), ['token' => $resetToken, 'email' => $user->email, 'password' => 'brand-new-passphrase', 'password_confirmation' => 'brand-new-passphrase']);

        $stored = AuditLog::withoutTenantScope()->pluck('details')->implode(' ');
        foreach ([$inviteToken, $resetToken, 'brand-new-passphrase'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        $this->assertSame(['ok' => true, 'checked' => AuditLog::withoutTenantScope()->count(), 'problems' => []], app(AuditTrail::class)->verify($this->sacco->id));
    }

    public function test_the_member_portal_gained_no_new_routes_and_no_self_registration_exists(): void
    {
        $portalRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'portal'))->map(fn ($r) => $r->uri().'|'.implode(',', $r->methods()))->all();
        $this->assertNotContains('portal/forgot-password|GET,HEAD', $portalRoutes);

        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }
}
