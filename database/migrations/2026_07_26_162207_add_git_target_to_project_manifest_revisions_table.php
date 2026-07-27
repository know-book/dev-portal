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
        Schema::table('project_manifest_revisions', function (Blueprint $table) {
            $table->string('git_repository')->nullable()->after('git_commit_sha');
            $table->string('git_branch')->nullable()->after('git_repository');
            $table->string('git_path')->nullable()->after('git_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_manifest_revisions', function (Blueprint $table) {
            $table->dropColumn(['git_repository', 'git_branch', 'git_path']);
        });
    }
};
