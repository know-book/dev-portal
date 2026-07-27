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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('gitops_repository_mode')->default('co_located')->after('auto_deploy')->index();
            $table->foreignId('gitops_github_installation_id')->nullable()->after('gitops_repository_mode')->constrained('github_installations')->nullOnDelete();
            $table->string('gitops_repository')->nullable()->after('gitops_github_installation_id');
            $table->string('gitops_repository_id')->nullable()->after('gitops_repository');
            $table->string('gitops_branch')->nullable()->after('gitops_repository_id');
            $table->string('gitops_path')->default('deploy/k8s')->after('gitops_branch');
            $table->string('gitops_publish_mode')->default('direct')->after('gitops_path')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['gitops_github_installation_id']);
            $table->dropColumn([
                'gitops_repository_mode',
                'gitops_github_installation_id',
                'gitops_repository',
                'gitops_repository_id',
                'gitops_branch',
                'gitops_path',
                'gitops_publish_mode',
            ]);
        });
    }
};
