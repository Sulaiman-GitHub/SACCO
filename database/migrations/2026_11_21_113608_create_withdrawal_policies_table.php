<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->unique()->constrained('saccos')->cascadeOnDelete();
            $table->unsignedSmallInteger('notice_days')->default(7);
            $table->string('loan_cover_mode', 30)->default('block_while_loan_active'); // block_while_loan_active | keep_ratio_cover
            $table->decimal('early_withdrawal_fee_percent', 5, 2)->default(0); // 0 = early withdrawal not offered
            $table->decimal('daily_limit_per_member', 15, 2)->nullable()->default(1000000); // null = no limit
            $table->decimal('admin_approval_threshold', 15, 2)->nullable()->default(500000); // null = administrator always
            $table->unsignedSmallInteger('security_hold_days')->default(3); // 0 = off
            $table->unsignedSmallInteger('request_expiry_days')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_policies');
    }
};
