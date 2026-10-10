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
        Schema::create('repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('repayment_reference', 50);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30)->default('cash'); // cash, mtn_momo, airtel_money, bank_transfer, other
            $table->string('payment_reference', 100)->nullable();
            $table->date('repayment_date');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sacco_id', 'repayment_reference']);
            $table->index(['sacco_id', 'loan_id']);
            $table->index(['sacco_id', 'member_id']);
            $table->index(['loan_id', 'repayment_date']);
            $table->index(['sacco_id', 'repayment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repayments');
    }
};
