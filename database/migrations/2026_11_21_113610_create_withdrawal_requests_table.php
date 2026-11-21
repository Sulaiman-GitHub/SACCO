<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('reference', 30);
            $table->decimal('amount', 15, 2);
            $table->boolean('early')->default(false);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('destination_phone', 20);
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|paid|cancelled|expired
            $table->date('available_from');
            $table->string('member_notes', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_method', 20)->nullable();
            $table->string('payout_reference', 100)->nullable();
            // One request can point at one ledger row at most, so a second ledger row is impossible
            $table->foreignId('saving_id')->nullable()->unique()->constrained('savings')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['sacco_id', 'reference']);
            $table->index(['sacco_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
