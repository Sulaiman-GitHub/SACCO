<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    /**
     * Super Admin platform overview.
     */
    public function dashboard(): View
    {
        $totalSaccos = Sacco::count();
        $activeSaccos = Sacco::where('status', 'active')->count();
        $totalUsers = User::withoutTenantScope()->where('role', '!=', 'super_admin')->count();
        $recentSaccos = Sacco::withCount('users')->latest()->take(5)->get();

        return view('super_admin.dashboard', compact('totalSaccos', 'activeSaccos', 'totalUsers', 'recentSaccos'));
    }

    /**
     * List all SACCO tenants with search and filtering.
     */
    public function saccosIndex(Request $request): View
    {
        $query = Sacco::withCount('users');

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('license_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tier')) {
            $query->where('tier', $request->get('tier'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        $saccos = $query->latest()->paginate(15)->withQueryString();

        return view('super_admin.saccos.index', compact('saccos'));
    }

    /**
     * Show form to onboard a new SACCO and its primary administrator.
     */
    public function createSacco(): View
    {
        return view('super_admin.saccos.create');
    }

    /**
     * Store new SACCO and initial administrator.
     */
    public function storeSacco(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // SACCO entity details
            'name' => ['required', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100', 'unique:saccos,license_number'],
            'tier' => ['required', 'string', Rule::in(['Tier 1', 'Tier 2', 'Tier 3', 'Tier 4'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],

            // Primary SACCO Admin credentials
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'admin_phone' => ['nullable', 'string', 'max:50'],
        ]);

        DB::transaction(function () use ($validated) {
            $sacco = Sacco::create([
                'name' => $validated['name'],
                'license_number' => $validated['license_number'] ?? null,
                'tier' => $validated['tier'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'status' => 'active',
            ]);

            User::withoutTenantScope()->create([
                'sacco_id' => $sacco->id,
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'role' => 'sacco_admin',
                'phone' => $validated['admin_phone'] ?? null,
                'status' => 'active',
            ]);
        });

        return redirect()->route('super_admin.saccos.index')
            ->with('success', "SACCO '{$validated['name']}' and primary administrator onboarded successfully.");
    }

    /**
     * Show details of a specific SACCO tenant.
     */
    public function showSacco(Sacco $sacco): View
    {
        $users = User::withoutTenantScope()->where('sacco_id', $sacco->id)->latest()->get();

        return view('super_admin.saccos.show', compact('sacco', 'users'));
    }

    /**
     * Toggle active/suspended status for a SACCO.
     */
    public function toggleSaccoStatus(Sacco $sacco): RedirectResponse
    {
        $newStatus = $sacco->status === 'active' ? 'suspended' : 'active';
        $sacco->update(['status' => $newStatus]);

        return back()->with('success', "SACCO '{$sacco->name}' status updated to {$newStatus}.");
    }
}
