<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('admissions_plans')->onDelete('cascade');
            $table->unsignedInteger('version_no');
            $table->enum('state', [
                'draft', 'submitted', 'under_review', 'returned',
                'approved', 'published', 'rejected', 'archived', 'superseded'
            ])->default('draft');
            $table->date('effective_date')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('artifact_hash', 64)->nullable();
            $table->json('snapshot_data')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version_no']);
            $table->index('state');
            $table->index('created_by');
        });

        // Add foreign key back to admissions_plans
        Schema::table('admissions_plans', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('admissions_plan_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions_plans', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('admissions_plan_versions');
    }
};
