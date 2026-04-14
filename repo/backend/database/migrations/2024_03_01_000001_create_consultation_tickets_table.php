<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('local_ticket_no', 30)->unique();
            $table->unsignedBigInteger('applicant_id');
            $table->string('category_tag', 50);
            $table->enum('priority', ['Normal', 'High']);
            $table->string('department_id', 50)->nullable();
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->enum('status', [
                'new', 'triaged', 'reassigned', 'in_progress',
                'waiting_applicant', 'resolved', 'reopened',
                'auto_closed', 'closed'
            ])->default('new');
            $table->timestamp('first_response_due_at')->nullable();
            $table->boolean('overdue_flag')->default(false);
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('initial_message');
            $table->timestamps();

            $table->index('applicant_id');
            $table->index('advisor_id');
            $table->index('department_id');
            $table->index('status');
            $table->index('priority');
            $table->index('overdue_flag');
            $table->index('created_at');
            $table->foreign('applicant_id')->references('id')->on('users');
            $table->foreign('advisor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('consultation_tickets')->onDelete('cascade');
            $table->unsignedBigInteger('sender_user_id');
            $table->text('message_text');
            $table->timestamp('created_at');

            $table->index('ticket_id');
            $table->index('created_at');
        });

        Schema::create('consultation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('consultation_tickets')->onDelete('cascade');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('storage_path', 500);
            $table->string('sha256_fingerprint', 64);
            $table->enum('upload_status', ['pending', 'completed', 'quarantined', 'failed'])->default('pending');
            $table->text('quarantine_reason')->nullable();
            $table->timestamps();

            $table->index('ticket_id');
            $table->index('upload_status');
        });

        Schema::create('ticket_routing_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('consultation_tickets')->onDelete('cascade');
            $table->string('from_department', 50)->nullable();
            $table->string('to_department', 50)->nullable();
            $table->unsignedBigInteger('from_advisor')->nullable();
            $table->unsignedBigInteger('to_advisor')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('created_at');

            $table->index('ticket_id');
        });

        Schema::create('ticket_quality_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('sampled_week', 10);
            $table->unsignedBigInteger('advisor_id');
            $table->foreignId('ticket_id')->constrained('consultation_tickets');
            $table->unsignedBigInteger('reviewer_manager_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->enum('review_state', ['pending', 'in_review', 'completed'])->default('pending');
            $table->unsignedInteger('score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sampled_week', 'advisor_id', 'ticket_id']);
            $table->index('advisor_id');
            $table->index('sampled_week');
            $table->index('review_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_quality_reviews');
        Schema::dropIfExists('ticket_routing_history');
        Schema::dropIfExists('consultation_attachments');
        Schema::dropIfExists('consultation_messages');
        Schema::dropIfExists('consultation_tickets');
    }
};
