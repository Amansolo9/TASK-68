<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('dictionary_type', 100);
            $table->string('code', 100);
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->string('validation_rule_ref', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['dictionary_type', 'code']);
            $table->index('dictionary_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_dictionaries');
    }
};
