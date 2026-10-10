<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('loan_reference', 50);
            $table->decimal('principal', 15, 2);
            $table->decimal('interest_rate', 5, 2)->default(10.00); // e.g. 10.00 for 10%
            $table->string('interest_calculation_type', 20)->default('flat'); // flat, simple
            $table->unsignedInteger('term_months')->default(6);
            $table->decimal('total_interest', 15, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2); // principal + total_interest
            $table->string('purpose', 255)->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, disbursed, overdue, defaulted, closed
            $table->date('applied_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disbursement_method', 30)->nullable(); // cash, mtn_momo, airtel_money, bank_transfer, other
            $table->string('disbursement_reference', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->string('guarantor_name', 255)->nullable();
            $table->string('guarantor_phone', 50)->nullable();
            $table->string('guarantor_relationship', 100)->nullable();
            $table->decimal('risk_score', 5, 2)->nullable(); // 0-100 score from ML model (Module 5)
            $table->string('decision', 50)->nullable(); // rule engine recommendation (Module 6)
            $table->text('decision_reason')->nullable(); // explainable reasoning
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sacco_id', 'loan_reference']);
            $table->index(['sacco_id', 'member_id']);
            $table->index(['sacco_id', 'status']);
            $table->index(['sacco_id', 'due_date']);
            $table->index(['member_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
