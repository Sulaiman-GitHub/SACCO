<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Saving;
use App\Models\User;
use App\Models\WithdrawalPolicy;
use App\Models\WithdrawalRequest;
use App\Support\UgandaPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every step of a savings withdrawal request. The member, SACCO, amount fee and destination always
 * come from the database, never from the browser. A ledger row is created in exactly one place,
 * recordPayout(), through Saving::recordLocked().
 */
class WithdrawalRequestService
{
    /**
     * What the member could still ask for: the savings balance less their own open requests.
     */
    public function availableBalance(Member $member): float
    {
        $reserved = (float) WithdrawalRequest::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->open()
            ->sum('amount');

        return round($member->savings_balance - $reserved, 2);
    }

    /**
     * When new requests are blocked after a password or phone change, or null when they are not.
     */
    public function securityHoldEndsAt(Member $member, WithdrawalPolicy $policy): ?Carbon
    {
        if ($policy->security_hold_days <= 0) {
            return null;
        }

        $latest = collect([$member->portal_password_changed_at, $member->phone_changed_at])->filter()->max();

        if ($latest === null) {
            return null;
        }

        $ends = Carbon::parse($latest)->addDays($policy->security_hold_days);

        return $ends->isFuture() ? $ends : null;
    }

    /**
     * Create a request for the member. Runs under a lock on the member row so two submissions
     * cannot both pass the one-open-request and balance checks.
     *
     * @throws ValidationException
     */
    public function submit(Member $member, float $amount, bool $early, ?string $notes): WithdrawalRequest
    {
        return DB::transaction(function () use ($member, $amount, $early, $notes) {
            $member = Member::withoutTenantScope()
                ->where('sacco_id', $member->sacco_id)
                ->lockForUpdate()
                ->findOrFail($member->id);

            $saccoId = (int) $member->sacco_id;
            $policy = WithdrawalPolicy::resolveFor($saccoId);
            $today = Carbon::today();

            if (! $member->isActive() || ! $member->canUsePortal()) {
                $this->fail('amount', 'Your account is not active, so a withdrawal cannot be requested. Please contact your SACCO.');
            }

            $destination = UgandaPhone::normalise($member->phone);
            if ($destination === null) {
                $this->fail('amount', 'There is no valid mobile number on your record, so we cannot pay you. Please ask your SACCO to add one.');
            }

            if (WithdrawalRequest::withoutTenantScope()->where('sacco_id', $saccoId)->where('member_id', $member->id)->open()->exists()) {
                $this->fail('amount', 'You already have a withdrawal request waiting. Cancel it or wait until it is paid before asking for another.');
            }

            $holdEnds = $this->securityHoldEndsAt($member, $policy);
            if ($holdEnds !== null) {
                $this->fail('amount', 'For your safety, withdrawals are paused after a password or phone number change. You can ask again from '.$holdEnds->format('d M Y').'.');
            }

            if ($early && ! $policy->offersEarlyWithdrawal()) {
                $this->fail('early', 'Early withdrawal is not offered by your SACCO. Please choose the normal notice period.');
            }

            $available = $this->availableBalance($member);

            if ($amount > $available + 0.005) {
                $this->fail('amount', 'You can withdraw at most UGX '.number_format(max(0, $available), 2).' (your savings less any request already waiting).');
            }

            if (! Saving::meetsMinimum($amount, $available)) {
                $this->fail('amount', 'The minimum withdrawal is UGX '.number_format(Saving::MINIMUM_AMOUNT).', unless you are withdrawing your whole balance of UGX '.number_format($available, 2).'.');
            }

            $coverError = $policy->loanCoverError($member, $amount, $available);
            if ($coverError !== null) {
                $this->fail('amount', $coverError);
            }

            $limitError = $policy->dailyLimitError($amount, $this->withdrawnOrRequestedOn($member, $today));
            if ($limitError !== null) {
                $this->fail('amount', $limitError);
            }

            $fee = $early ? $policy->earlyFeeFor($amount) : 0.0;

            $request = new WithdrawalRequest([
                'sacco_id' => $saccoId,
                'member_id' => $member->id,
                'reference' => WithdrawalRequest::generateReference($saccoId),
                'amount' => round($amount, 2),
                'early' => $early,
                'fee_amount' => $fee,
                'net_amount' => round($amount - $fee, 2),
                'destination_phone' => $destination,
                'status' => WithdrawalRequest::STATUS_PENDING,
                'available_from' => $early ? $today : $today->copy()->addDays($policy->notice_days),
                'member_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]);
            $request->save();

            app(AuditTrail::class)->record('withdrawal.requested', $request, [
                'reference' => $request->reference,
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'amount' => (float) $request->amount,
                'early' => $early,
                'fee' => (float) $request->fee_amount,
                'net' => (float) $request->net_amount,
                'available_from' => $request->available_from->toDateString(),
                'requested_by' => 'member_portal',
            ]);

            return $request;
        });
    }

    /**
     * Withdrawn today (ledger) plus open requests the member made today.
     */
    private function withdrawnOrRequestedOn(Member $member, Carbon $day): float
    {
        $ledger = (float) Saving::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->where('type', 'withdrawal')
            ->whereDate('transaction_date', $day->toDateString())
            ->sum('amount');

        $requested = (float) WithdrawalRequest::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->open()
            ->whereDate('created_at', $day->toDateString())
            ->sum('amount');

        return $ledger + $requested;
    }

    /**
     * The member cancels their own request before it is paid.
     *
     * @throws ValidationException
     */
    public function cancel(Member $member, int $requestId): WithdrawalRequest
    {
        return DB::transaction(function () use ($member, $requestId) {
            $request = $this->lockOwn($member, $requestId);

            if (! $request->isOpen()) {
                $this->fail('request', 'This request can no longer be cancelled.');
            }

            $request->forceFill(['status' => WithdrawalRequest::STATUS_CANCELLED, 'cancelled_at' => Carbon::now()])->save();

            app(AuditTrail::class)->record('withdrawal.cancelled', $request, [
                'reference' => $request->reference,
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'amount' => (float) $request->amount,
                'changed_by' => 'member_portal',
            ]);

            return $request;
        });
    }

    /**
     * Approve a pending request. Only from the available date, and above the SACCO's threshold
     * only a SACCO admin may approve.
     *
     * @throws ValidationException
     */
    public function approve(User $staff, int $requestId): WithdrawalRequest
    {
        return DB::transaction(function () use ($staff, $requestId) {
            $request = $this->lockForStaff($staff, $requestId);

            if ($request->status !== WithdrawalRequest::STATUS_PENDING) {
                $this->fail('request', 'Only a request that is waiting for review can be approved.');
            }

            $policy = WithdrawalPolicy::resolveFor((int) $request->sacco_id);
            $amount = (float) $request->amount;

            if ($policy->needsAdminApproval($amount) && ! $staff->isSaccoAdmin()) {
                $this->fail('request', 'Requests of this size must be approved by a SACCO administrator.');
            }

            if ($request->available_from->isFuture()) {
                $this->fail('request', 'This request cannot be approved before '.$request->available_from->format('d M Y').', when the notice period ends.');
            }

            $member = Member::withoutTenantScope()->where('sacco_id', $request->sacco_id)->findOrFail($request->member_id);
            $problem = $this->moneyProblem($member, $request);
            if ($problem !== null) {
                $this->fail('request', $problem);
            }

            $request->forceFill([
                'status' => WithdrawalRequest::STATUS_APPROVED,
                'decided_by' => $staff->id,
                'decided_at' => Carbon::now(),
            ])->save();

            app(AuditTrail::class)->record('withdrawal.approved', $request, [
                'reference' => $request->reference,
                'member_id' => $request->member_id,
                'member_number' => $member->member_number,
                'amount' => (float) $request->amount,
                'above_threshold' => $policy->needsAdminApproval($amount),
            ]);

            return $request;
        });
    }

    /**
     * Reject a request that is not yet paid. A reason is required.
     *
     * @throws ValidationException
     */
    public function reject(User $staff, int $requestId, string $reason): WithdrawalRequest
    {
        return DB::transaction(function () use ($staff, $requestId, $reason) {
            $request = $this->lockForStaff($staff, $requestId);

            if (! $request->isOpen()) {
                $this->fail('request', 'This request can no longer be rejected.');
            }

            $request->forceFill([
                'status' => WithdrawalRequest::STATUS_REJECTED,
                'decided_by' => $staff->id,
                'decided_at' => Carbon::now(),
                'decision_reason' => $reason,
            ])->save();

            app(AuditTrail::class)->record('withdrawal.rejected', $request, [
                'reference' => $request->reference,
                'member_id' => $request->member_id,
                'amount' => (float) $request->amount,
                'reason' => $reason,
            ]);

            return $request;
        });
    }

    /**
     * Record that staff paid the member by hand. In one transaction: lock the request, confirm it is
     * still approved and unpaid, check the money rules again, write the single ledger row, link it,
     * and mark the request paid. A repeated or simultaneous call finds it already paid and fails.
     *
     * @throws ValidationException
     */
    public function recordPayout(User $staff, int $requestId, string $method, ?string $reference): WithdrawalRequest
    {
        return DB::transaction(function () use ($staff, $requestId, $method, $reference) {
            $request = $this->lockForStaff($staff, $requestId);

            if ($request->status !== WithdrawalRequest::STATUS_APPROVED || $request->saving_id !== null) {
                $this->fail('request', $request->status === WithdrawalRequest::STATUS_PAID
                    ? 'This request has already been paid. Nothing was recorded twice.'
                    : 'Only an approved request can be paid out.');
            }

            $payerError = WithdrawalPolicy::resolveFor((int) $request->sacco_id)
                ->payerRuleError((float) $request->amount, $request->decided_by, $staff->id);
            if ($payerError !== null) {
                $this->fail('request', $payerError);
            }

            if ($request->available_from->isFuture()) {
                $this->fail('request', 'This request cannot be paid before '.$request->available_from->format('d M Y').', when the notice period ends.');
            }

            // recordLocked() locks the member and checks balance, loan cover and the daily limit again
            $saving = Saving::recordLocked([
                'member_id' => $request->member_id,
                'type' => 'withdrawal',
                'amount' => $request->amount,
                'payment_method' => $method,
                'payment_reference' => $reference,
                'transaction_date' => Carbon::today()->toDateString(),
                'notes' => 'Withdrawal request '.$request->reference,
            ], (int) $request->sacco_id, $staff->id);

            $request->forceFill([
                'status' => WithdrawalRequest::STATUS_PAID,
                'paid_by' => $staff->id,
                'paid_at' => Carbon::now(),
                'payout_method' => $method,
                'payout_reference' => $reference,
                'saving_id' => $saving->id,
            ])->save();

            app(AuditTrail::class)->record('withdrawal.paid', $request, [
                'reference' => $request->reference,
                'member_id' => $request->member_id,
                'amount' => (float) $request->amount,
                'fee' => (float) $request->fee_amount,
                'net' => (float) $request->net_amount,
                'payout_method' => $method,
                'payout_reference' => $reference,
                'savings_reference' => $saving->transaction_reference,
            ]);

            return $request;
        });
    }

    /**
     * Expire open requests that were not paid within the SACCO's expiry window. Returns how many.
     */
    public function expireStale(): int
    {
        $expired = 0;
        $today = Carbon::today();

        WithdrawalRequest::withoutTenantScope()->open()->orderBy('id')->each(function (WithdrawalRequest $candidate) use ($today, &$expired) {
            $policy = WithdrawalPolicy::resolveFor((int) $candidate->sacco_id);

            if (! $candidate->available_from->copy()->addDays($policy->request_expiry_days)->lt($today)) {
                return;
            }

            DB::transaction(function () use ($candidate, &$expired) {
                $request = WithdrawalRequest::withoutTenantScope()->lockForUpdate()->find($candidate->id);

                if ($request === null || ! $request->isOpen()) {
                    return;
                }

                $request->forceFill(['status' => WithdrawalRequest::STATUS_EXPIRED, 'expired_at' => Carbon::now()])->save();

                app(AuditTrail::class)->record('withdrawal.expired', $request, [
                    'reference' => $request->reference,
                    'member_id' => $request->member_id,
                    'amount' => (float) $request->amount,
                    'reason' => 'Not paid within the allowed days',
                ]);

                $expired++;
            });
        });

        return $expired;
    }

    /**
     * The first reason the member's money rules would block this request now, or null. Used when a
     * request is approved. Payout runs the same rules inside Saving::recordLocked().
     */
    public function moneyProblem(Member $member, WithdrawalRequest $request): ?string
    {
        $amount = (float) $request->amount;
        $balance = $member->savings_balance;
        $policy = WithdrawalPolicy::resolveFor((int) $request->sacco_id);

        if (! $member->isActive()) {
            return 'The member is not active, so this cannot go ahead.';
        }

        if ($amount > $balance + 0.005) {
            return 'The member only has UGX '.number_format($balance, 2).' in savings now, which is less than this request.';
        }

        $coverError = $policy->loanCoverError($member, $amount, $balance);
        if ($coverError !== null) {
            return $coverError;
        }

        $already = (float) Saving::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->where('type', 'withdrawal')
            ->whereDate('transaction_date', Carbon::today()->toDateString())
            ->sum('amount');

        return $policy->dailyLimitError($amount, $already);
    }

    private function lockOwn(Member $member, int $requestId): WithdrawalRequest
    {
        return WithdrawalRequest::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->lockForUpdate()
            ->findOrFail($requestId);
    }

    private function lockForStaff(User $staff, int $requestId): WithdrawalRequest
    {
        return WithdrawalRequest::withoutTenantScope()
            ->where('sacco_id', $staff->sacco_id)
            ->lockForUpdate()
            ->findOrFail($requestId);
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
