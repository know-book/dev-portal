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
            $table->string('initialization_status')->default('pending')->after('auto_deploy')->index();
            $table->text('initialization_error')->nullable()->after('initialization_status');
            $table->timestamp('initialized_at')->nullable()->after('initialization_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['initialization_status', 'initialization_error', 'initialized_at']);
        });
    }
};
