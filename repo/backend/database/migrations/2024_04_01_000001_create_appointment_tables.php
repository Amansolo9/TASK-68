<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->string('slot_type', 50);
            $table->string('department_id', 50)->nullable();
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('available_qty');
            $table->boolean('pre_deduct_mode')->default(true);
            $table->enum('status', ['open', 'full', 'closed', 'cancelled'])->default('open');
            $table->timestamps();

            $table->index(['start_at', 'status']);
            $table->index('department_id');
            $table->index('advisor_id');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->foreignId('slot_id')->constrained('appointment_slots');
            $table->string('booking_type', 50)->default('standard');
            $table->enum('state', [
                'pending', 'booked', 'rescheduled', 'cancelled', 'completed', 'no_show', 'expired'
            ])->default('pending');
            $table->string('request_key', 64)->nullable();
            $table->timestamp('request_key_expires_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('no_show_marked_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->index('applicant_id');
            $table->index('slot_id');
            $table->index('state');
            $table->index('request_key');
            $table->foreign('applicant_id')->references('id')->on('users');
        });

        Schema::create('appointment_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->string('from_state', 50)->nullable();
            $table->string('to_state', 50);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('ip_address', 45)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('transitioned_at');

            $table->index('appointment_id');
        });

        Schema::create('slot_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('appointment_slots');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('correlation_key', 64)->nullable();
            $table->unsignedInteger('reserved_qty')->default(1);
            $table->timestamp('expires_at');
            $table->enum('status', ['held', 'confirmed', 'released', 'expired'])->default('held');
            $table->timestamps();

            $table->index(['slot_id', 'status']);
            $table->index('expires_at');
            $table->index('correlation_key');
        });

        // Database-backed distributed locks
        Schema::create('distributed_locks', function (Blueprint $table) {
            $table->id();
            $table->string('lock_key', 255)->unique();
            $table->string('owner', 100);
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributed_locks');
        Schema::dropIfExists('slot_reservations');
        Schema::dropIfExists('appointment_state_history');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_slots');
    }
};
