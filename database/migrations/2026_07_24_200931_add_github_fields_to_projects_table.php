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
            $table->foreignId('github_installation_id')->nullable()->after('team_id')->constrained('github_installations')->nullOnDelete();
            $table->string('repository_id')->nullable()->after('repository');
            $table->string('default_branch')->default('main')->after('repository_id');
            $table->boolean('auto_deploy')->default(true)->after('default_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['github_installation_id']);
            $table->dropColumn(['github_installation_id', 'repository_id', 'default_branch', 'auto_deploy']);
        });
    }
};
