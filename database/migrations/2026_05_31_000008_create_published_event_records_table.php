<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_event_records', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('operation', 64);
            $table->string('payment_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('routing_key', 128);
            $table->string('message_id', 128);
            $table->string('correlation_id', 128);
            $table->string('causation_id', 128);
            $table->string('schema_version', 16);
            $table->string('occurred_at', 32);
            $table->string('producer', 64);
            $table->json('payload');
            $table->string('response_fingerprint', 128);
            $table->timestamps();

            $table->unique(['operation', 'payment_id', 'idempotency_key']);
            $table->unique('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_event_records');
    }
};
