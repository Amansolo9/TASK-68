<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('route', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->json('request_summary')->nullable();
            $table->string('outcome', 50);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            $table->index('correlation_id');
            $table->index('user_id');
            $table->index('created_at');
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
};
