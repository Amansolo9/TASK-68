<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only state transition log
        Schema::create('plan_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('admissions_plan_versions')->onDelete('cascade');
            $table->string('from_state', 50)->nullable();
            $table->string('to_state', 50);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('actor_role', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('before_hash', 64)->nullable();
            $table->string('after_hash', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('transitioned_at');

            $table->index('version_id');
            $table->index('to_state');
            $table->index('transitioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_state_history');
    }
};
