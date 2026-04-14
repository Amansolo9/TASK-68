<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role', 50)->nullable();
            $table->string('entity_type', 100);
            $table->string('entity_id', 100)->nullable();
            $table->string('event_type', 100);
            $table->string('ip_address', 45)->nullable();
            $table->string('before_hash', 64)->nullable();
            $table->string('after_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('chain_hash', 64);
            $table->timestamp('created_at');

            $table->index('actor_user_id');
            $table->index('entity_type');
            $table->index(['entity_type', 'entity_id']);
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
