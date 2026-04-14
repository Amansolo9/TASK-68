<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['applicant', 'advisor', 'manager', 'steward', 'admin']);
            $table->string('department_scope')->nullable();
            $table->string('entity_scope')->nullable();
            $table->json('section_permissions')->nullable();
            $table->json('content_permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'role', 'department_scope'], 'user_role_dept_unique');
            $table->index('role');
            $table->index('department_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_scopes');
    }
};
