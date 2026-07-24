<?php

namespace App\Models;

use App\Enums\ProjectFramework;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $github_installation_id
 * @property string $name
 * @property string $slug
 * @property ProjectFramework $framework
 * @property string|null $repository
 * @property string|null $repository_id
 * @property string $default_branch
 * @property bool $auto_deploy
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read GitHubInstallation|null $githubInstallation
 */
#[Fillable(['team_id', 'github_installation_id', 'name', 'slug', 'framework', 'repository', 'repository_id', 'default_branch', 'auto_deploy', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = static::generateUniqueSlug($project->team_id, $project->name);
            }
        });

        static::updating(function (Project $project) {
            if ($project->isDirty('name')) {
                $project->slug = static::generateUniqueSlug($project->team_id, $project->name, $project->id);
            }
        });
    }

    /**
     * Generate a unique slug for a project within a team.
     */
    public static function generateUniqueSlug(int $teamId, string $name, ?int $excludeId = null): string
    {
        $defaultSlug = Str::slug($name) ?: 'project';

        $query = static::withTrashed()
            ->where('team_id', $teamId)
            ->where(function ($q) use ($defaultSlug) {
                $q->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        if ($existingSlugs->isEmpty()) {
            return $defaultSlug;
        }

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($defaultSlug): ?int {
                if ($slug === $defaultSlug) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $defaultSlug.'-'.($maxSuffix + 1);
    }

    /**
     * Get the team that owns the project.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the GitHub installation for this project.
     *
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function githubInstallation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'framework' => ProjectFramework::class,
            'auto_deploy' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
