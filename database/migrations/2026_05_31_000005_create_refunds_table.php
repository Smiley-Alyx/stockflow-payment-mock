<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('refund_id', 64)->unique();
            $table->string('payment_id', 64);
            $table->string('payment_attempt_id', 64);
            $table->string('capture_id', 64);
            $table->unsignedBigInteger('amount_value');
            $table->string('amount_currency', 3);
            $table->string('status', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_message', 255)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->foreign('payment_attempt_id')
                ->references('id')
                ->on('payment_attempts')
                ->cascadeOnDelete();

            $table->foreign('capture_id')
                ->references('id')
                ->on('captures')
                ->cascadeOnDelete();

            $table->index('payment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
