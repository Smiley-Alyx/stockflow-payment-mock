<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('payment_id', 64);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('idempotency_key', 128);
            $table->string('message_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_message', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->unique(['payment_id', 'type', 'idempotency_key']);
            $table->index(['payment_id', 'type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
