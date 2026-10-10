<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Sacco $sacco;

    private User $admin;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sacco = Sacco::factory()->create();
        $this->admin = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'sacco_admin', 'status' => 'active', 'name' => 'Admin Anne']);
        $this->member = Member::factory()->create(['sacco_id' => $this->sacco->id, 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function entries(?string $action = null)
    {
        return AuditLog::withoutTenantScope()->where('sacco_id', $this->sacco->id)->when($action, fn ($q) => $q->where('action', $action))->orderBy('id')->get();
    }

    public function test_savings_and_repayments_are_audited_with_actor(): void
    {
        $saving = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'type' => 'deposit', 'amount' => 25000]);
        $loan = Loan::factory()->disbursed()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        $repayment = Repayment::factory()->create(['sacco_id' => $this->sacco->id, 'loan_id' => $loan->id, 'member_id' => $this->member->id, 'amount' => 40000]);

        $savingLog = $this->entries('savings.recorded')->first();
        $this->assertSame('Saving', $savingLog->entity_type);
        $this->assertSame($saving->id, (int) $savingLog->entity_id);
        $this->assertSame('Admin Anne', $savingLog->user_name);
        $this->assertEquals(25000.0, $savingLog->detailsArray()['amount']);

        $this->assertEquals(40000.0, $this->entries('repayment.recorded')->first()->detailsArray()['amount']);
        $this->assertSame($repayment->id, (int) $this->entries('repayment.recorded')->first()->entity_id);
    }

    public function test_loan_lifecycle_is_audited_and_overrides_are_flagged(): void
    {
        $loan = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'decision' => 'reject']);
        $this->assertCount(1, $this->entries('loan.applied'));

        $loan->approve($this->admin, 'Committee accepted collateral.');
        $loan->disburse($this->admin, 'cash', null, now()->format('Y-m-d'));

        $approved = $this->entries('loan.approved')->first();
        $this->assertTrue($approved->isOverride());
        $this->assertSame('Committee accepted collateral.', $approved->detailsArray()['notes']);
        $this->assertCount(1, $this->entries('loan.disbursed'));

        $second = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'decision' => 'approve']);
        $second->reject($this->admin, 'Changed circumstances.');
        $this->assertTrue($this->entries('loan.rejected')->first()->isOverride());

        $third = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'decision' => 'approve']);
        $third->approve($this->admin);
        $this->assertFalse($this->entries('loan.approved')->last()->isOverride());
    }

    public function test_policy_changes_record_old_and_new_values(): void
    {
        $policy = LendingPolicy::resolveFor($this->sacco->id);
        $this->assertCount(0, $this->entries('policy.updated'));

        $policy->update(['approve_below_score' => 40]);

        $changes = $this->entries('policy.updated')->first()->detailsArray()['changes'];
        $this->assertEquals([50.0, 40], $changes['approve_below_score']);
    }

    public function test_entries_cannot_be_modified_or_deleted(): void
    {
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        $log = $this->entries()->first();

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'something.else']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);

        $this->expectException(RuntimeException::class);
        $this->entries()->first()->delete();
    }

    public function test_intact_trail_verifies(): void
    {
        Saving::factory()->count(3)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);

        $result = app(AuditTrail::class)->verify($this->sacco->id);

        $this->assertTrue($result['ok'], implode('; ', $result['problems']));
        $this->assertSame(3, $result['checked']);
    }

    public function test_verification_detects_a_tampered_entry(): void
    {
        Saving::factory()->count(2)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        $first = $this->entries()->first();

        DB::table('audit_logs')->where('id', $first->id)->update(['user_name' => 'Someone Else']);

        $result = app(AuditTrail::class)->verify($this->sacco->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("Entry #{$first->id}", $result['problems'][0]);
    }

    public function test_verification_detects_a_removed_entry(): void
    {
        Saving::factory()->count(3)->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        $middle = $this->entries()->get(1);

        DB::table('audit_logs')->where('id', $middle->id)->delete();

        $result = app(AuditTrail::class)->verify($this->sacco->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('chain is broken', implode(' ', $result['problems']));
    }

    public function test_verification_detects_an_altered_ledger_amount(): void
    {
        $saving = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'amount' => 10000]);

        DB::table('savings')->where('id', $saving->id)->update(['amount' => 99999]);

        $result = app(AuditTrail::class)->verify($this->sacco->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('amount in the ledger differs', $result['problems'][0]);
    }

    public function test_verification_detects_a_deleted_ledger_row(): void
    {
        $saving = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);

        DB::table('savings')->where('id', $saving->id)->delete();

        $result = app(AuditTrail::class)->verify($this->sacco->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no longer exists', $result['problems'][0]);
    }

    public function test_each_sacco_has_its_own_chain(): void
    {
        $other = Sacco::factory()->create();
        $otherMember = Member::factory()->create(['sacco_id' => $other->id, 'status' => 'active']);

        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id]);
        Saving::factory()->create(['sacco_id' => $other->id, 'member_id' => $otherMember->id]);

        $otherFirst = AuditLog::withoutTenantScope()->where('sacco_id', $other->id)->first();

        $this->assertSame(AuditLog::GENESIS_HASH, $otherFirst->prev_hash);
        $this->assertTrue(app(AuditTrail::class)->verify($other->id)['ok']);
        $this->assertTrue(app(AuditTrail::class)->verify($this->sacco->id)['ok']);
    }

    public function test_audit_log_page_is_admin_only_and_tenant_scoped(): void
    {
        $other = Sacco::factory()->create();
        $otherMember = Member::factory()->create(['sacco_id' => $other->id, 'status' => 'active']);
        Saving::factory()->create(['sacco_id' => $other->id, 'member_id' => $otherMember->id, 'transaction_reference' => 'SAV-OTHER-SACCO']);
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'transaction_reference' => 'SAV-MINE-1']);

        $officer = User::factory()->create(['sacco_id' => $this->sacco->id, 'role' => 'loan_officer', 'status' => 'active']);
        $this->actingAs($officer)->get(route('tenant.audit_log.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('tenant.audit_log.verify'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('tenant.audit_log.index'))
            ->assertOk()
            ->assertSee('SAV-MINE-1')
            ->assertDontSee('SAV-OTHER-SACCO');
    }

    public function test_audit_log_page_filters_overrides_and_reports_verification(): void
    {
        $loan = Loan::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'decision' => 'reject', 'loan_reference' => 'LN-OVERRIDDEN']);
        $loan->approve($this->admin, 'Reason given.');
        Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'transaction_reference' => 'SAV-NOT-OVERRIDE']);

        $this->get(route('tenant.audit_log.index', ['overrides' => 1]))
            ->assertOk()
            ->assertSee('LN-OVERRIDDEN')
            ->assertDontSee('SAV-NOT-OVERRIDE');

        $this->post(route('tenant.audit_log.verify'))->assertRedirect(route('tenant.audit_log.index'));
        $this->followingRedirects()->post(route('tenant.audit_log.verify'))->assertSee('Integrity check passed');
    }

    public function test_verify_command_reports_success_and_failure(): void
    {
        $saving = Saving::factory()->create(['sacco_id' => $this->sacco->id, 'member_id' => $this->member->id, 'amount' => 5000]);

        $this->artisan('audit:verify', ['sacco_id' => $this->sacco->id])->assertSuccessful();

        DB::table('savings')->where('id', $saving->id)->update(['amount' => 1]);

        $this->artisan('audit:verify', ['sacco_id' => $this->sacco->id])->assertFailed();
    }
}
