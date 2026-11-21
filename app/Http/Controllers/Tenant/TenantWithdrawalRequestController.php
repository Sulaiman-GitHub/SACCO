<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordWithdrawalPayoutRequest;
use App\Http\Requests\RejectWithdrawalRequest;
use App\Models\Loan;
use App\Models\WithdrawalPolicy;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Staff side of savings withdrawal requests: review, approve or reject, then record the manual payout.
 * Staff can never edit the amount, member or destination of a request.
 */
class TenantWithdrawalRequestController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $requests = WithdrawalRequest::query()
            ->with('member')
            ->status($status)
            ->search($search)
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('tenant.withdrawals.index', [
            'requests' => $requests,
            'search' => $search,
            'status' => $status,
            'pendingCount' => WithdrawalRequest::where('status', WithdrawalRequest::STATUS_PENDING)->count(),
        ]);
    }

    public function show(WithdrawalRequest $withdrawal): View
    {
        $this->ensureOwnSacco($withdrawal);

        $withdrawal->load(['member', 'decider', 'payer', 'ledgerEntry']);
        $member = $withdrawal->member;
        $policy = WithdrawalPolicy::resolveFor((int) $withdrawal->sacco_id);
        $service = app(WithdrawalRequestService::class);

        return view('tenant.withdrawals.show', [
            'withdrawal' => $withdrawal,
            'member' => $member,
            'policy' => $policy,
            'balance' => $member->savings_balance,
            'loans' => Loan::where('member_id', $member->id)->whereIn('status', Loan::STATUS_GROUPS['outstanding'])->get(),
            'needsAdmin' => $policy->needsAdminApproval((float) $withdrawal->amount),
            'payerBlock' => $policy->payerRuleError((float) $withdrawal->amount, $withdrawal->decided_by, (int) Auth::id()),
            'moneyProblem' => $withdrawal->isOpen() ? $service->moneyProblem($member, $withdrawal) : null,
        ]);
    }

    public function approve(WithdrawalRequestService $service, WithdrawalRequest $withdrawal): RedirectResponse
    {
        $this->ensureOwnSacco($withdrawal);
        $this->ensureStaff();

        return $this->run(fn () => $service->approve(Auth::user(), $withdrawal->id), $withdrawal, "Request {$withdrawal->reference} approved. Pay the member, then record the payout here.");
    }

    public function reject(RejectWithdrawalRequest $request, WithdrawalRequestService $service, WithdrawalRequest $withdrawal): RedirectResponse
    {
        $this->ensureOwnSacco($withdrawal);

        return $this->run(fn () => $service->reject(Auth::user(), $withdrawal->id, $request->validated('decision_reason')), $withdrawal, "Request {$withdrawal->reference} rejected.");
    }

    public function pay(RecordWithdrawalPayoutRequest $request, WithdrawalRequestService $service, WithdrawalRequest $withdrawal): RedirectResponse
    {
        $this->ensureOwnSacco($withdrawal);

        return $this->run(
            fn () => $service->recordPayout(Auth::user(), $withdrawal->id, $request->validated('payout_method'), $request->validated('payout_reference')),
            $withdrawal,
            "Payout recorded for request {$withdrawal->reference}. The savings balance has been updated."
        );
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, WithdrawalRequest $withdrawal, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return redirect()->route('tenant.withdrawals.show', $withdrawal)->withErrors($e->errors());
        }

        return redirect()->route('tenant.withdrawals.show', $withdrawal)->with('success', $success);
    }

    private function ensureOwnSacco(WithdrawalRequest $withdrawal): void
    {
        if ((int) $withdrawal->sacco_id !== (int) Auth::user()->sacco_id) {
            abort(404);
        }
    }

    private function ensureStaff(): void
    {
        $user = Auth::user();

        abort_unless($user->isSaccoAdmin() || $user->isLoanOfficer(), 403);
    }
}
