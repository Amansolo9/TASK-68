<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions_plans', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 20);
            $table->string('intake_batch', 100);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamps();

            $table->unique(['academic_year', 'intake_batch']);
            $table->index('academic_year');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions_plans');
    }
};
