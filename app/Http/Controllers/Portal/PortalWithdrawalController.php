<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\Member;
use App\Models\WithdrawalPolicy;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalRequestService;
use App\Support\UgandaPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Members asking to withdraw their own savings. Every query starts from the signed-in member, and
 * the browser never supplies a member, SACCO, destination or fee. Staff pay out by hand later.
 */
class PortalWithdrawalController extends Controller
{
    private const MAX_PASSWORD_FAILURES = 5;

    private const PASSWORD_LOCK_SECONDS = 900;

    public function create(Request $request, WithdrawalRequestService $service): Response
    {
        $member = $request->user('member');
        $policy = WithdrawalPolicy::resolveFor((int) $member->sacco_id);

        return $this->noStore(response()->view('portal.withdraw', [
            'member' => $member,
            'policy' => $policy,
            'available' => $service->availableBalance($member),
            'openRequest' => $this->ownRequests($member)->open()->latest('id')->first(),
            'holdEndsAt' => $service->securityHoldEndsAt($member, $policy),
            'hasPhone' => UgandaPhone::normalise($member->phone) !== null,
        ]));
    }

    public function store(StoreWithdrawalRequest $request, WithdrawalRequestService $service): RedirectResponse
    {
        /** @var Member $member */
        $member = $request->user('member');
        $key = 'withdrawal-password:'.$member->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->withInput($request->except('password'))->withErrors([
                'password' => "Too many incorrect passwords. Try again in {$minutes} minute(s).",
            ]);
        }

        if (! Hash::check((string) $request->validated('password'), $member->getAuthPassword())) {
            RateLimiter::hit($key, self::PASSWORD_LOCK_SECONDS);

            return back()->withInput($request->except('password'))->withErrors(['password' => 'That password is not correct.']);
        }

        RateLimiter::clear($key);

        try {
            $withdrawal = $service->submit(
                $member,
                (float) $request->validated('amount'),
                $request->boolean('early'),
                $request->validated('notes'),
            );
        } catch (ValidationException $e) {
            return back()->withInput($request->except('password'))->withErrors($e->errors());
        }

        return redirect()->route('portal.withdrawals.show', $withdrawal->id)
            ->with('success', "Your withdrawal request {$withdrawal->reference} has been sent to the SACCO. You can follow its progress here.");
    }

    public function index(Request $request): Response
    {
        $member = $request->user('member');

        return $this->noStore(response()->view('portal.withdrawals', [
            'member' => $member,
            'requests' => $this->ownRequests($member)->latest('id')->paginate(15),
        ]));
    }

    public function show(Request $request, string $withdrawal): Response
    {
        $member = $request->user('member');

        return $this->noStore(response()->view('portal.withdrawal_show', [
            'member' => $member,
            'withdrawal' => $this->ownRequests($member)->findOrFail($withdrawal),
        ]));
    }

    public function cancel(Request $request, WithdrawalRequestService $service, string $withdrawal): RedirectResponse
    {
        $member = $request->user('member');

        try {
            $cancelled = $service->cancel($member, (int) $this->ownRequests($member)->findOrFail($withdrawal)->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('portal.withdrawals.show', $cancelled->id)->with('success', "Your withdrawal request {$cancelled->reference} was cancelled.");
    }

    /**
     * @return Builder<WithdrawalRequest>
     */
    private function ownRequests(Member $member)
    {
        return WithdrawalRequest::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id);
    }

    private function noStore(Response $response): Response
    {
        return $response->header('Cache-Control', 'no-store, private');
    }
}
