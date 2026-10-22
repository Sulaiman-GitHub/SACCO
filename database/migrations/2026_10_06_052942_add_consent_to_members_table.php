<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing members get no consent record (consent_version stays null) and a null scoring choice,
     * meaning "not recorded yet". Nothing is assumed on a member's behalf.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('consent_version', 20)->nullable()->after('notes'); // notice version the member agreed to; null = none recorded
            $table->timestamp('consented_at')->nullable()->after('consent_version');
            $table->foreignId('consent_captured_by')->nullable()->after('consented_at')->constrained('users')->nullOnDelete();
            $table->boolean('scoring_consent')->nullable()->after('consent_captured_by'); // null = not recorded, true = agreed, false = declined
            $table->boolean('next_of_kin_informed')->default(false)->after('scoring_consent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consent_captured_by');
            $table->dropColumn(['consent_version', 'consented_at', 'scoring_consent', 'next_of_kin_informed']);
        });
    }
};
