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
        Schema::create('project_manifest_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_manifest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('patch_snapshot')->nullable();
            $table->string('compiled_hash', 64);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('git_commit_sha')->nullable();
            $table->timestamps();

            $table->unique(['project_manifest_id', 'revision_number']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_manifest_revisions');
    }
};
