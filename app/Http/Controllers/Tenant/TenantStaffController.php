<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantStaffController extends Controller
{
    /**
     * List all staff members belonging to the authenticated user's SACCO.
     */
    public function index(): View
    {
        // Automatically scoped to logged-in user's sacco_id via TenantScope
        $staff = User::where('role', '!=', 'super_admin')->latest()->paginate(15);

        return view('tenant.staff.index', compact('staff'));
    }

    /**
     * Show form to add a staff member within current SACCO.
     */
    public function create(): View
    {
        return view('tenant.staff.create');
    }

    /**
     * Store new staff member in current SACCO.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['loan_officer', 'sacco_admin'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // BelongsToTenant trait automatically attaches Auth::user()->sacco_id
        User::create([
            'sacco_id' => Auth::user()->sacco_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'active',
        ]);

        return redirect()->route('tenant.staff.index')
            ->with('success', "Staff member '{$validated['name']}' created successfully.");
    }

    /**
     * Toggle active/inactive status for a staff member.
     */
    public function toggleStatus(User $user): RedirectResponse
    {
        // Ensure user belongs to the current SACCO (TenantScope will already ensure this, but double check)
        if ($user->sacco_id !== Auth::user()->sacco_id) {
            abort(403, 'You can only manage staff of your own SACCO.');
        }

        // Prevent self-deactivation
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return back()->with('success', "Staff member '{$user->name}' status changed to {$newStatus}.");
    }
}
