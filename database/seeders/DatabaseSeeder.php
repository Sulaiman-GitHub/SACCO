<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use App\Models\User;
use Database\Factories\MemberFactory;
use Database\Factories\SavingFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Platform Super Administrator
        User::withoutTenantScope()->firstOrCreate(
            ['email' => 'admin@fattech.ug'],
            [
                'sacco_id' => null,
                'name' => 'System Operator',
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
                'phone' => '+256 700 000001',
                'status' => 'active',
            ]
        );

        // 2. Test SACCO 1: Kampala Teachers SACCO
        $sacco1 = Sacco::firstOrCreate(
            ['license_number' => 'UMRA/SACCO/2026/001'],
            [
                'name' => 'Kampala Teachers SACCO',
                'tier' => 'Tier 2',
                'phone' => '+256 701 111222',
                'email' => 'info@kampalateachers.ug',
                'address' => 'Plot 14 Lumumba Avenue, Kampala',
                'status' => 'active',
            ]
        );

        $admin1 = User::withoutTenantScope()->firstOrCreate(
            ['email' => 'grace@teachers.ug'],
            [
                'sacco_id' => $sacco1->id,
                'name' => 'Grace Atuhaire',
                'password' => Hash::make('password123'),
                'role' => 'sacco_admin',
                'phone' => '+256 772 100200',
                'status' => 'active',
            ]
        );

        User::withoutTenantScope()->firstOrCreate(
            ['email' => 'peter@teachers.ug'],
            [
                'sacco_id' => $sacco1->id,
                'name' => 'Peter Okello',
                'password' => Hash::make('password123'),
                'role' => 'loan_officer',
                'phone' => '+256 772 300400',
                'status' => 'active',
            ]
        );

        // 3. Test SACCO 2: Jinja Farmers Cooperative SACCO
        $sacco2 = Sacco::firstOrCreate(
            ['license_number' => 'UMRA/SACCO/2026/045'],
            [
                'name' => 'Jinja Farmers Cooperative SACCO',
                'tier' => 'Tier 3',
                'phone' => '+256 702 333444',
                'email' => 'contact@jinjafarmers.ug',
                'address' => 'Main Street, Jinja City',
                'status' => 'active',
            ]
        );

        $admin2 = User::withoutTenantScope()->firstOrCreate(
            ['email' => 'david@farmers.ug'],
            [
                'sacco_id' => $sacco2->id,
                'name' => 'David Mukasa',
                'password' => Hash::make('password123'),
                'role' => 'sacco_admin',
                'phone' => '+256 782 500600',
                'status' => 'active',
            ]
        );

        User::withoutTenantScope()->firstOrCreate(
            ['email' => 'amina@farmers.ug'],
            [
                'sacco_id' => $sacco2->id,
                'name' => 'Amina Nabirye',
                'password' => Hash::make('password123'),
                'role' => 'loan_officer',
                'phone' => '+256 782 700800',
                'status' => 'active',
            ]
        );

        // 4. Seed realistic sample members for both SACCOs if not already present. Each one gets the
        // same consent record a real registration produces (data processing and scoring agreed,
        // timestamped, captured by the SACCO admin), because members without one are never scored.
        if (Member::withoutTenantScope()->where('sacco_id', $sacco1->id)->count() === 0) {
            $this->seedConsentedMembers($sacco1, $admin1, 10);
        }

        if (Member::withoutTenantScope()->where('sacco_id', $sacco2->id)->count() === 0) {
            $this->seedConsentedMembers($sacco2, $admin2, 6);
        }

        // 5. Seed sample savings transactions for SACCO members
        if (Saving::withoutTenantScope()->count() === 0) {
            $members = Member::withoutTenantScope()->get();
            foreach ($members as $member) {
                // Initial and subsequent deposits
                SavingFactory::new()->count(3)->create([
                    'sacco_id' => $member->sacco_id,
                    'member_id' => $member->id,
                    'type' => 'deposit',
                ]);

                // Occasional withdrawal if balance is sufficient
                if ($member->savings_balance > 80000) {
                    SavingFactory::new()->create([
                        'sacco_id' => $member->sacco_id,
                        'member_id' => $member->id,
                        'type' => 'withdrawal',
                        'amount' => 50000,
                    ]);
                }
            }
        }
    }

    /**
     * Create members for a SACCO, each with a consent record captured by the given staff user.
     * The user is authenticated while seeding so the audit entries name them, as in a real request.
     */
    private function seedConsentedMembers(Sacco $sacco, User $capturedBy, int $count): void
    {
        Auth::setUser($capturedBy);

        try {
            MemberFactory::new()
                ->count($count)
                ->consented($capturedBy)
                ->create(['sacco_id' => $sacco->id]);
        } finally {
            Auth::forgetUser();
        }
    }
}
