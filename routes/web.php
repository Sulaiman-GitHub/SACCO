<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\Tenant\TenantAuditLogController;
use App\Http\Controllers\Tenant\TenantDashboardController;
use App\Http\Controllers\Tenant\TenantLendingPolicyController;
use App\Http\Controllers\Tenant\TenantLoanController;
use App\Http\Controllers\Tenant\TenantMemberController;
use App\Http\Controllers\Tenant\TenantMemberPortalController;
use App\Http\Controllers\Tenant\TenantRepaymentController;
use App\Http\Controllers\Tenant\TenantReportController;
use App\Http\Controllers\Tenant\TenantSavingsController;
use App\Http\Controllers\Tenant\TenantStaffController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Root redirect
Route::get('/', function () {
    if (Auth::check()) {
        return Auth::user()->isSuperAdmin()
            ? redirect()->route('super_admin.dashboard')
            : redirect()->route('tenant.dashboard');
    }

    if (Auth::guard('member')->check()) {
        return redirect()->route('portal.dashboard');
    }

    return redirect()->route('login');
});

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Member Portal (Module 10): read-only, separate `member` guard
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:member')->group(function () {
        Route::get('/login', [PortalAuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [PortalAuthController::class, 'login'])->name('login.attempt');
    });

    Route::middleware('member_portal')->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
        Route::get('/password', [PortalAuthController::class, 'editPassword'])->name('password.edit');
        Route::put('/password', [PortalAuthController::class, 'updatePassword'])->name('password.update');

        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/savings', [PortalController::class, 'savings'])->name('savings');
        Route::get('/loans', [PortalController::class, 'loans'])->name('loans');
        Route::get('/consent', [PortalController::class, 'consent'])->name('consent');
        Route::post('/consent-requests', [PortalController::class, 'storeConsentRequest'])->name('consent_requests.store');
    });
});

// Platform Super-Admin Routes
Route::middleware(['auth', 'super_admin'])->prefix('super-admin')->name('super_admin.')->group(function () {
    Route::get('/dashboard', [SuperAdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/saccos', [SuperAdminController::class, 'saccosIndex'])->name('saccos.index');
    Route::get('/saccos/create', [SuperAdminController::class, 'createSacco'])->name('saccos.create');
    Route::post('/saccos', [SuperAdminController::class, 'storeSacco'])->name('saccos.store');
    Route::get('/saccos/{sacco}', [SuperAdminController::class, 'showSacco'])->name('saccos.show');
    Route::post('/saccos/{sacco}/toggle-status', [SuperAdminController::class, 'toggleSaccoStatus'])->name('saccos.toggle_status');
});

// Tenant Workspace Routes (Isolated by sacco_id)
Route::middleware(['auth', 'tenant_user'])->group(function () {
    Route::get('/dashboard', [TenantDashboardController::class, 'index'])->name('tenant.dashboard');

    // Member Management (Module 2)
    Route::prefix('members')->name('tenant.members.')->group(function () {
        Route::get('/', [TenantMemberController::class, 'index'])->name('index');
        Route::get('/create', [TenantMemberController::class, 'create'])->name('create');
        Route::post('/', [TenantMemberController::class, 'store'])->name('store');
        Route::get('/{member}', [TenantMemberController::class, 'show'])->name('show');
        Route::get('/{member}/edit', [TenantMemberController::class, 'edit'])->name('edit');
        Route::put('/{member}', [TenantMemberController::class, 'update'])->name('update');
        Route::post('/{member}/consent', [TenantMemberController::class, 'updateConsent'])->name('update_consent');
        Route::post('/{member}/toggle-status', [TenantMemberController::class, 'toggleStatus'])->name('toggle_status');
        Route::get('/{member}/savings-statement', [TenantSavingsController::class, 'memberStatement'])->name('savings_statement');

        // Member portal access and consent requests (Module 10)
        Route::post('/{member}/portal/credentials', [TenantMemberPortalController::class, 'issueCredentials'])->name('portal_credentials');
        Route::post('/{member}/portal/disable', [TenantMemberPortalController::class, 'disable'])->name('portal_disable');
        Route::post('/{member}/consent-requests/{consentRequest}', [TenantMemberPortalController::class, 'resolveRequest'])->name('consent_requests.resolve');
    });

    // Savings & Contributions Ledger (Module 3)
    Route::prefix('savings')->name('tenant.savings.')->group(function () {
        Route::get('/', [TenantSavingsController::class, 'index'])->name('index');
        Route::get('/create', [TenantSavingsController::class, 'create'])->name('create');
        Route::post('/', [TenantSavingsController::class, 'store'])->name('store');
        Route::get('/{saving}', [TenantSavingsController::class, 'show'])->name('show');
    });

    // Loans Management (Module 4)
    Route::prefix('loans')->name('tenant.loans.')->group(function () {
        Route::get('/', [TenantLoanController::class, 'index'])->name('index');
        Route::get('/create', [TenantLoanController::class, 'create'])->name('create');
        Route::post('/', [TenantLoanController::class, 'store'])->name('store');
        Route::get('/{loan}', [TenantLoanController::class, 'show'])->name('show');
        Route::post('/{loan}/approve', [TenantLoanController::class, 'approve'])->name('approve');
        Route::post('/{loan}/reject', [TenantLoanController::class, 'reject'])->name('reject');
        Route::post('/{loan}/disburse', [TenantLoanController::class, 'disburse'])->name('disburse');
    });

    // Repayments Ledger (Module 4)
    Route::prefix('repayments')->name('tenant.repayments.')->group(function () {
        Route::get('/', [TenantRepaymentController::class, 'index'])->name('index');
        Route::get('/create', [TenantRepaymentController::class, 'create'])->name('create');
        Route::post('/', [TenantRepaymentController::class, 'store'])->name('store');
        Route::get('/{repayment}', [TenantRepaymentController::class, 'show'])->name('show');
    });

    // SACCO Admin specific routes
    Route::middleware('sacco_admin')->prefix('staff')->name('tenant.staff.')->group(function () {
        Route::get('/', [TenantStaffController::class, 'index'])->name('index');
        Route::get('/create', [TenantStaffController::class, 'create'])->name('create');
        Route::post('/', [TenantStaffController::class, 'store'])->name('store');
        Route::post('/{user}/toggle-status', [TenantStaffController::class, 'toggleStatus'])->name('toggle_status');
    });

    // Management reports and exports (Module 7)
    Route::middleware('sacco_admin')->prefix('reports')->name('tenant.reports.')->group(function () {
        Route::get('/', [TenantReportController::class, 'index'])->name('index');
        Route::get('/savings.csv', [TenantReportController::class, 'exportSavings'])->name('savings_csv');
        Route::get('/loans.csv', [TenantReportController::class, 'exportLoans'])->name('loans_csv');
    });

    // Immutable audit trail (Module 8)
    Route::middleware('sacco_admin')->prefix('audit-log')->name('tenant.audit_log.')->group(function () {
        Route::get('/', [TenantAuditLogController::class, 'index'])->name('index');
        Route::post('/verify', [TenantAuditLogController::class, 'verify'])->name('verify');
    });

    // Lending policy thresholds for the rule engine (Module 6)
    Route::middleware('sacco_admin')->prefix('settings/lending-policy')->name('tenant.lending_policy.')->group(function () {
        Route::get('/', [TenantLendingPolicyController::class, 'edit'])->name('edit');
        Route::put('/', [TenantLendingPolicyController::class, 'update'])->name('update');
    });
});
