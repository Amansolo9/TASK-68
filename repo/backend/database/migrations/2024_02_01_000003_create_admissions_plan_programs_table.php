<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions_plan_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('admissions_plan_versions')->onDelete('cascade');
            $table->string('program_code', 50);
            $table->string('program_name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('planned_capacity')->nullable();
            $table->text('capacity_notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('version_id');
            $table->unique(['version_id', 'program_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions_plan_programs');
    }
};
