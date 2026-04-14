<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('full_name', 255);
            $table->text('encrypted_date_of_birth')->nullable();
            $table->text('encrypted_government_id')->nullable();
            $table->text('encrypted_institutional_id')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('department_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'locked'])->default('active');
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamp('lockout_until')->nullable();
            $table->boolean('totp_enabled')->default(false);
            $table->boolean('mfa_verified_this_session')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
