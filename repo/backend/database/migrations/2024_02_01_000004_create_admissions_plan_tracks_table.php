<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions_plan_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('admissions_plan_programs')->onDelete('cascade');
            $table->string('track_code', 50);
            $table->string('track_name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('planned_capacity')->nullable();
            $table->text('capacity_notes')->nullable();
            $table->string('admission_criteria', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('program_id');
            $table->unique(['program_id', 'track_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions_plan_tracks');
    }
};
