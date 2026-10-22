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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            // No foreign key on user_id: the entry must stay intact (and hash-valid) if a user is ever removed.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 255)->nullable(); // snapshot of the actor's name at the time
            $table->string('action', 50); // e.g. savings.recorded, loan.approved
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->text('details'); // JSON string, stored verbatim so the hash stays reproducible
            $table->char('prev_hash', 64); // hash of the previous entry in this SACCO's chain
            $table->char('hash', 64); // sha256 over this entry's fields plus prev_hash
            $table->timestamp('created_at');

            $table->index(['sacco_id', 'id']);
            $table->index(['sacco_id', 'action']);
            $table->index(['sacco_id', 'entity_type', 'entity_id']);
            $table->index(['sacco_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
