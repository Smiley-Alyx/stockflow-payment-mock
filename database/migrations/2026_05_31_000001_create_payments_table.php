<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('payment_id', 64)->unique();
            $table->string('order_id', 64);
            $table->string('customer_id', 64);
            $table->unsignedBigInteger('amount_value');
            $table->string('amount_currency', 3);
            $table->string('status', 32);
            $table->string('capture_mode', 16);
            $table->string('payment_method_type', 32);
            $table->string('payment_method_token', 128);
            $table->json('metadata')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
