<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberConsentTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create();
        $this->officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'member_number' => 'MBR-7001',
            'full_name' => 'Agnes Namatovu',
            'national_id' => 'CF900141029ABC',
            'phone' => '+256 772 000111',
            'join_date' => '2026-01-10',
            'consent_data_processing' => '1',
            'scoring_consent' => '1',
            'next_of_kin_informed' => '1',
        ], $overrides);
    }

    public function test_registration_records_consent_with_version_date_and_staff_member(): void
    {
        $this->actingAs($this->officer)->post(route('tenant.members.store'), $this->registrationPayload())
            ->assertSessionHasNoErrors();

        $member = Member::where('member_number', 'MBR-7001')->firstOrFail();

        $this->assertSame(Member::CONSENT_NOTICE_VERSION, $member->consent_version);
        $this->assertNotNull($member->consented_at);
        $this->assertSame($this->officer->id, $member->consent_captured_by);
        $this->assertTrue($member->scoring_consent);
        $this->assertTrue($member->next_of_kin_informed);

        $entry = AuditLog::withoutTenantScope()->where('action', 'member.consent_recorded')->firstOrFail();
        $this->assertSame($member->id, $entry->entity_id);
        $this->assertSame($this->officer->id, $entry->user_id);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_registration_is_refused_without_data_processing_consent(): void
    {
        $this->actingAs($this->officer)
            ->post(route('tenant.members.store'), $this->registrationPayload(['consent_data_processing' => '']))
            ->assertSessionHasErrors('consent_data_processing');

        $this->assertSame(0, Member::count());
    }

    public function test_scoring_choice_must_be_answered_but_declining_is_allowed(): void
    {
        $payload = $this->registrationPayload();
        unset($payload['scoring_consent']);

        $this->actingAs($this->officer)->post(route('tenant.members.store'), $payload)
            ->assertSessionHasErrors('scoring_consent');

        $this->actingAs($this->officer)
            ->post(route('tenant.members.store'), $this->registrationPayload(['scoring_consent' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Member::where('member_number', 'MBR-7001')->firstOrFail()->scoring_consent);
    }

    public function test_consent_fields_cannot_be_set_through_the_ordinary_member_update(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);

        $this->actingAs($this->officer)->put(route('tenant.members.update', $member), [
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'phone' => $member->phone,
            'join_date' => '2025-01-01',
            'scoring_consent' => '1',
            'consent_version' => '9.9',
        ])->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->consent_version);
        $this->assertNull($member->fresh()->scoring_consent);
    }

    public function test_consent_can_be_withdrawn_and_the_audit_log_keeps_the_previous_choice(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $this->actingAs($this->officer);
        $member->recordConsent($this->officer, true, true);

        $this->post(route('tenant.members.update_consent', $member), [
            'consent_data_processing' => '1',
            'scoring_consent' => '0',
            'next_of_kin_informed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->hasDeclinedScoring());

        $latest = AuditLog::withoutTenantScope()->where('action', 'member.consent_recorded')->orderByDesc('id')->firstOrFail();
        $details = json_decode((string) $latest->getRawOriginal('details'), true);
        $this->assertFalse($details['scoring_consent']);
        $this->assertTrue($details['previous_scoring_consent']);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_other_saccos_staff_cannot_change_a_members_consent(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $otherOfficer = User::factory()->create(['sacco_id' => Sacco::factory()->create()->id, 'role' => 'loan_officer', 'status' => 'active']);

        $this->actingAs($otherOfficer)->post(route('tenant.members.update_consent', $member), [
            'consent_data_processing' => '1',
            'scoring_consent' => '0',
        ])->assertNotFound();

        $this->assertNull($member->fresh()->scoring_consent);
    }

    public function test_a_member_who_declined_scoring_can_still_borrow_and_is_not_scored(): void
    {
        Http::fake();
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $this->actingAs($this->officer);
        $member->recordConsent($this->officer, false, false);

        $this->post(route('tenant.loans.store'), [
            'member_id' => $member->id,
            'principal' => 500000,
            'interest_rate' => 10,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'applied_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        Http::assertNothingSent();

        $loan = Loan::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('pending', $loan->status);
        $this->assertNull($loan->risk_score);
        $this->assertNull($loan->decision);
        $this->assertStringContainsString('declined credit scoring', $loan->decision_reason);
        $this->assertFalse($loan->isApprovalOverride());

        $this->get(route('tenant.loans.show', $loan))
            ->assertOk()
            ->assertSee('Credit scoring declined by member');
        Http::assertNothingSent();
    }

    public function test_a_member_with_no_consent_record_is_never_scored(): void
    {
        Http::fake();
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $this->actingAs($this->officer);

        $this->post(route('tenant.loans.store'), [
            'member_id' => $member->id,
            'principal' => 500000,
            'interest_rate' => 10,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'applied_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $loan = Loan::where('member_id', $member->id)->firstOrFail();
        $this->assertSame('pending', $loan->status);
        $this->assertNull($loan->risk_score);
        $this->assertNull($loan->decision);
        $this->assertStringContainsString('No consent record', $loan->decision_reason);

        $this->get(route('tenant.loans.show', $loan))
            ->assertOk()
            ->assertSee('No consent record for credit scoring');
        Http::assertNothingSent();

        $this->artisan('credit:score-member', ['member_id' => $member->id])
            ->expectsOutputToContain('has no consent record')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_member_who_agreed_to_scoring_is_scored(): void
    {
        Http::fake(['*/score' => Http::failedConnection()]);
        $member = Member::factory()->consented($this->officer)->create(['sacco_id' => $this->sacco->id]);
        $this->actingAs($this->officer);

        $this->post(route('tenant.loans.store'), [
            'member_id' => $member->id,
            'principal' => 500000,
            'interest_rate' => 10,
            'interest_calculation_type' => 'flat',
            'term_months' => 6,
            'applied_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Loan::where('member_id', $member->id)->firstOrFail()->risk_score);
    }

    public function test_scoring_consent_cannot_be_given_without_data_processing_consent(): void
    {
        $this->actingAs($this->officer)
            ->post(route('tenant.members.store'), $this->registrationPayload(['consent_data_processing' => '', 'scoring_consent' => '1']))
            ->assertSessionHasErrors(['consent_data_processing', 'scoring_consent']);

        $this->assertSame(0, Member::count());

        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);
        $this->post(route('tenant.members.update_consent', $member), ['consent_data_processing' => '', 'scoring_consent' => '1'])
            ->assertSessionHasErrors(['consent_data_processing', 'scoring_consent']);

        $this->assertFalse($member->fresh()->hasRecordedConsent());
    }

    public function test_no_to_both_questions_does_not_register_the_member(): void
    {
        $this->actingAs($this->officer)
            ->post(route('tenant.members.store'), $this->registrationPayload(['consent_data_processing' => '', 'scoring_consent' => '0']))
            ->assertSessionHasErrors('consent_data_processing')
            ->assertSessionDoesntHaveErrors('scoring_consent');

        $this->assertSame(0, Member::count());
    }

    public function test_seeded_members_all_have_a_consent_record_that_allows_scoring(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Member::withoutTenantScope()->count());
        Member::withoutTenantScope()->get()->each(function (Member $member) {
            $this->assertTrue($member->canBeScored());
            $this->assertNotNull($member->consented_at);
            $this->assertNotNull($member->consent_captured_by);
        });
        $this->assertSame(
            Member::withoutTenantScope()->count(),
            AuditLog::withoutTenantScope()->where('action', 'member.consent_recorded')->count()
        );
    }

    public function test_member_profile_shows_the_consent_record(): void
    {
        $member = Member::factory()->create(['sacco_id' => $this->sacco->id]);

        $this->actingAs($this->officer)->get(route('tenant.members.show', $member))
            ->assertOk()->assertSee('No consent has been recorded');

        $member->recordConsent($this->officer, false, true);

        $this->actingAs($this->officer)->get(route('tenant.members.show', $member))
            ->assertOk()->assertSee('Declined')->assertSee(Member::CONSENT_NOTICE_VERSION);
    }
}
