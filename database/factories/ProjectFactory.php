<?php

namespace Database\Factories;

use App\Enums\ProjectFramework;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker?->words(2, true) ?? 'project-'.Str::random(4);

        return [
            'team_id' => Team::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'framework' => $this->faker?->randomElement([ProjectFramework::Laravel, ProjectFramework::NextJs, ProjectFramework::Other]) ?? ProjectFramework::Laravel,
            'repository' => ($this->faker?->userName() ?? 'org').'/'.Str::slug($name),
            'description' => $this->faker?->sentence() ?? 'Project description',
        ];
    }

    /**
     * State for Laravel project.
     */
    public function laravel(): static
    {
        return $this->state(fn (array $attributes) => [
            'framework' => ProjectFramework::Laravel,
        ]);
    }

    /**
     * State for NextJs project.
     */
    public function nextjs(): static
    {
        return $this->state(fn (array $attributes) => [
            'framework' => ProjectFramework::NextJs,
        ]);
    }
}
