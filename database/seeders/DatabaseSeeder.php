<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@admin.com',
        ]);

        if ($admin->currentTeam) {
            Project::factory()->laravel()->create([
                'team_id' => $admin->currentTeam->id,
                'name' => 'Demo Laravel App',
                'repository' => 'admin/demo-laravel',
                'description' => 'Demo Laravel 13 application with ArgoCD and Vault integration',
            ]);

            Project::factory()->nextjs()->create([
                'team_id' => $admin->currentTeam->id,
                'name' => 'Demo Next.js Frontend',
                'repository' => 'admin/demo-nextjs',
                'description' => 'Demo Next.js 15 standalone web app',
            ]);
        }
    }
}
