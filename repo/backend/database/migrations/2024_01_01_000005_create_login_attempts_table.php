<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100);
            $table->timestamp('attempted_at');
            $table->string('ip_address', 45);
            $table->enum('outcome', ['success', 'invalid_credentials', 'locked_out', 'mfa_required', 'mfa_failed', 'captcha_failed']);
            $table->boolean('captcha_required')->default(false);
            $table->string('user_agent', 500)->nullable();

            $table->index(['username', 'attempted_at']);
            $table->index(['ip_address', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
