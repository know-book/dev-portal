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
        Schema::create('project_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('preset_key');
            $table->string('preset_version');
            $table->json('variables');
            $table->string('base_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique('project_id');
            $table->index(['preset_key', 'preset_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_manifests');
    }
};
