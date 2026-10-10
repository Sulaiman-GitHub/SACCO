<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberConsentRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantMemberController extends Controller
{
    /**
     * Display a listing of SACCO members with search and filtering.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $status = $request->query('status');

        $query = Member::query()->with('creator');

        if (! empty($search)) {
            $query->search($search);
        }

        if (! empty($status) && $status !== 'all') {
            $query->status($status);
        }

        $members = $query->latest('id')->paginate(15)->withQueryString();

        $totalMembers = Member::count();
        $activeMembers = Member::where('status', 'active')->count();
        $inactiveMembers = Member::where('status', 'inactive')->count();
        $suspendedMembers = Member::where('status', 'suspended')->count();
        $newThisMonth = Member::where('join_date', '>=', Carbon::now()->startOfMonth())->count();

        return view('tenant.members.index', compact(
            'members',
            'search',
            'status',
            'totalMembers',
            'activeMembers',
            'inactiveMembers',
            'suspendedMembers',
            'newThisMonth'
        ));
    }

    /**
     * Show the form for creating a new SACCO member.
     */
    public function create(): View
    {
        $saccoId = (int) Auth::user()->sacco_id;
        $nextMemberNumber = Member::generateNextMemberNumber($saccoId);

        return view('tenant.members.create', compact('nextMemberNumber'));
    }

    /**
     * Store a newly created SACCO member in storage.
     */
    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $rawNin = (string) $request->input('national_id');

        $member = DB::transaction(function () use ($validated, $rawNin, $request) {
            $member = Member::create([
                'sacco_id' => Auth::user()->sacco_id,
                'member_number' => $validated['member_number'],
                'full_name' => $validated['full_name'],
                'national_id_hash' => Member::hashNationalId($rawNin),
                'national_id_masked' => Member::maskNationalId($rawNin),
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'occupation' => $validated['occupation'] ?? null,
                'address' => $validated['address'] ?? null,
                'monthly_income' => $validated['monthly_income'] ?? null,
                'next_of_kin_name' => $validated['next_of_kin_name'] ?? null,
                'next_of_kin_phone' => $validated['next_of_kin_phone'] ?? null,
                'next_of_kin_relationship' => $validated['next_of_kin_relationship'] ?? null,
                'join_date' => $validated['join_date'],
                'status' => $validated['status'] ?? 'active',
                'created_by' => Auth::id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            $member->recordConsent(Auth::user(), $request->boolean('scoring_consent'), $request->boolean('next_of_kin_informed'));

            return $member;
        });

        return redirect()->route('tenant.members.show', $member)
            ->with('success', "Member '{$member->full_name}' ({$member->member_number}) registered successfully.");
    }

    /**
     * Display the specified member profile and KYC details.
     */
    public function show(Member $member): View
    {
        $this->ensureTenantOwnership($member);

        $member->load('creator', 'sacco');

        return view('tenant.members.show', compact('member'));
    }

    /**
     * Show the form for editing the specified member.
     */
    public function edit(Member $member): View
    {
        $this->ensureTenantOwnership($member);

        return view('tenant.members.edit', compact('member'));
    }

    /**
     * Update the specified member in storage.
     */
    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $this->ensureTenantOwnership($member);

        $validated = $request->validated();

        $updateData = [
            'member_number' => $validated['member_number'],
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'address' => $validated['address'] ?? null,
            'monthly_income' => $validated['monthly_income'] ?? null,
            'next_of_kin_name' => $validated['next_of_kin_name'] ?? null,
            'next_of_kin_phone' => $validated['next_of_kin_phone'] ?? null,
            'next_of_kin_relationship' => $validated['next_of_kin_relationship'] ?? null,
            'join_date' => $validated['join_date'],
            'status' => $validated['status'] ?? $member->status,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($request->filled('national_id')) {
            $rawNin = (string) $request->input('national_id');
            $updateData['national_id_hash'] = Member::hashNationalId($rawNin);
            $updateData['national_id_masked'] = Member::maskNationalId($rawNin);
        }

        $member->update($updateData);

        return redirect()->route('tenant.members.show', $member)
            ->with('success', "Member '{$member->full_name}' updated successfully.");
    }

    /**
     * Record or change a member's consent, including declining or withdrawing credit-scoring consent.
     */
    public function updateConsent(UpdateMemberConsentRequest $request, Member $member): RedirectResponse
    {
        $this->ensureTenantOwnership($member);

        $member->recordConsent(Auth::user(), $request->boolean('scoring_consent'), $request->boolean('next_of_kin_informed'));

        return back()->with('success', "Consent recorded for '{$member->full_name}'.");
    }

    /**
     * Toggle active/inactive status for a member.
     */
    public function toggleStatus(Member $member): RedirectResponse
    {
        $this->ensureTenantOwnership($member);

        $newStatus = $member->status === 'active' ? 'inactive' : 'active';
        $member->update(['status' => $newStatus]);

        $statusLabel = $newStatus === 'active' ? 'activated' : 'deactivated';

        return back()->with('success', "Member '{$member->full_name}' has been {$statusLabel}.");
    }

    /**
     * Ensure the member belongs to the authenticated user's SACCO.
     */
    private function ensureTenantOwnership(Member $member): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $member->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Member record not found in your SACCO.');
        }
    }
}
