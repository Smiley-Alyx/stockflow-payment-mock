<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_cards', function (Blueprint $table) {
            $table->string('token', 128)->primary();
            $table->string('behavior', 64);
            $table->unsignedBigInteger('balance_value');
            $table->string('currency', 3);
            $table->string('brand', 32);
            $table->string('last_four', 4);
            $table->boolean('is_expired')->default(false);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_cards');
    }
};
