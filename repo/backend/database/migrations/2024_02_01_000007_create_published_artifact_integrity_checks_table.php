<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_artifact_integrity_checks', function (Blueprint $table) {
            $table->id();
            $table->string('artifact_type', 100);
            $table->unsignedBigInteger('artifact_id');
            $table->string('expected_hash', 64);
            $table->string('last_verified_hash', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->enum('status', ['pending', 'verified', 'compromised', 'error'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['artifact_type', 'artifact_id'], 'artifact_type_id_idx');
            $table->index('status', 'artifact_check_status_idx');
            $table->index('verified_at', 'artifact_check_verified_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_artifact_integrity_checks');
    }
};
