<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional stored comparison summaries for UI acceleration
        Schema::create('plan_version_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('left_version_id')->constrained('admissions_plan_versions')->onDelete('cascade');
            $table->foreignId('right_version_id')->constrained('admissions_plan_versions')->onDelete('cascade');
            $table->json('comparison_data');
            $table->string('comparison_hash', 64);
            $table->timestamp('computed_at');

            $table->unique(['left_version_id', 'right_version_id'], 'plan_ver_comp_left_right_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_version_comparisons');
    }
};
