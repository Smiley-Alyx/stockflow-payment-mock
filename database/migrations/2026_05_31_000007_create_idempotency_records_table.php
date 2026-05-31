<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('operation', 64);
            $table->string('idempotency_key', 128);
            $table->string('payment_id', 64);
            $table->string('payment_attempt_id', 64)->unique();
            $table->string('response_fingerprint', 128);
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->foreign('payment_attempt_id')
                ->references('id')
                ->on('payment_attempts')
                ->cascadeOnDelete();

            $table->unique(['operation', 'payment_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
