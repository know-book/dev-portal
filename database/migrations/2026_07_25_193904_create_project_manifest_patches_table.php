<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_manifest_patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_manifest_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('operation')->default('replace');
            $table->longText('content')->nullable();
            $table->string('base_content_hash', 64)->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_manifest_id', 'path']);
            $table->index('operation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_manifest_patches');
    }
};
