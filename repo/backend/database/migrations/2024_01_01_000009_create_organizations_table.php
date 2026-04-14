<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 255);
            $table->string('normalized_name', 255);
            $table->string('type', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->unsignedBigInteger('parent_org_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'retired'])->default('active');
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('normalized_name');
            $table->index('status');
            $table->index('parent_org_id');
            $table->foreign('parent_org_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('merged_into_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
