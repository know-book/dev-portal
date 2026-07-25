<?php

namespace Database\Seeders;

use App\Enums\ProjectFramework;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->currentTeam) {
            $teamName = "Admin User's Team";
            $team = Team::create([
                'name' => $teamName,
                'slug' => Team::generateUniqueTeamSlug($teamName),
                'is_personal' => true,
            ]);

            $team->members()->attach($admin, [
                'role' => TeamRole::Owner->value,
            ]);

            $admin->switchTeam($team);
        }

        $team = $admin->fresh()->currentTeam;

        if ($team) {
            Project::firstOrCreate(
                ['team_id' => $team->id, 'slug' => 'demo-laravel-app'],
                [
                    'name' => 'Demo Laravel App',
                    'framework' => ProjectFramework::Laravel,
                    'repository' => 'admin/demo-laravel',
                    'description' => 'Demo Laravel 13 application with ArgoCD and Vault integration',
                ]
            );

            Project::firstOrCreate(
                ['team_id' => $team->id, 'slug' => 'demo-nextjs-frontend'],
                [
                    'name' => 'Demo Next.js Frontend',
                    'framework' => ProjectFramework::NextJs,
                    'repository' => 'admin/demo-nextjs',
                    'description' => 'Demo Next.js 15 standalone web app',
                ]
            );
        }
    }
}
