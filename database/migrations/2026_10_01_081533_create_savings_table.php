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
        Schema::create('savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('transaction_reference', 50);
            $table->string('type', 20); // deposit, withdrawal
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 30)->default('cash'); // cash, mtn_momo, airtel_money, bank_transfer, other
            $table->string('payment_reference')->nullable();
            $table->date('transaction_date');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sacco_id', 'transaction_reference']);
            $table->index(['sacco_id', 'member_id']);
            $table->index(['sacco_id', 'type']);
            $table->index(['sacco_id', 'transaction_date']);
            $table->index(['member_id', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings');
    }
};
