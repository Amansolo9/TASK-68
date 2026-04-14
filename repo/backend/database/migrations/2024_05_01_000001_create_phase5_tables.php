<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('left_entity_id');
            $table->unsignedBigInteger('right_entity_id');
            $table->string('detection_basis', 255);
            $table->decimal('confidence', 5, 2)->default(0);
            $table->enum('status', ['pending', 'confirmed', 'rejected', 'merged'])->default('pending');
            $table->timestamps();

            $table->unique(['entity_type', 'left_entity_id', 'right_entity_id'], 'dup_cand_type_left_right_unique');
            $table->index(['entity_type', 'status'], 'dup_cand_type_status_idx');
        });

        Schema::create('merge_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100);
            $table->json('source_entity_ids');
            $table->unsignedBigInteger('target_entity_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['proposed', 'under_review', 'approved', 'rejected', 'cancelled', 'executed'])->default('proposed');
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('merge_metadata')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'status']);
            $table->index('requested_by');
        });

        Schema::create('data_quality_runs', function (Blueprint $table) {
            $table->id();
            $table->date('run_date');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->text('error_message')->nullable();

            $table->unique('run_date');
        });

        Schema::create('data_quality_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('data_quality_runs')->onDelete('cascade');
            $table->string('entity_type', 100);
            $table->string('metric_name', 100);
            $table->decimal('metric_value', 10, 4);
            $table->unsignedInteger('numerator')->nullable();
            $table->unsignedInteger('denominator')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'entity_type']);
            $table->index('metric_name');
        });

        Schema::create('trend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->string('metric_group', 100);
            $table->json('metric_values');
            $table->timestamps();

            $table->index(['report_date', 'metric_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_snapshots');
        Schema::dropIfExists('data_quality_metrics');
        Schema::dropIfExists('data_quality_runs');
        Schema::dropIfExists('merge_requests');
        Schema::dropIfExists('duplicate_candidates');
    }
};
