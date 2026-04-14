<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 50)->unique()->nullable();
            $table->string('full_name', 255);
            $table->string('normalized_name', 255);
            $table->text('encrypted_date_of_birth')->nullable();
            $table->text('encrypted_government_id')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->enum('status', ['active', 'inactive', 'retired'])->default('active');
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('normalized_name');
            $table->index('employee_id');
            $table->index('status');
            $table->foreign('merged_into_id')->references('id')->on('personnel')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
