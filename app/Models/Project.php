<?php

namespace App\Models;

use App\Enums\GitOpsPublishMode;
use App\Enums\GitOpsRepositoryMode;
use App\Enums\ProjectFramework;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property GitOpsRepositoryMode $gitops_repository_mode
 * @property int|null $gitops_github_installation_id
 * @property string|null $gitops_repository
 * @property string|null $gitops_repository_id
 * @property string|null $gitops_branch
 * @property string $gitops_path
 * @property GitOpsPublishMode $gitops_publish_mode
 * @property string $initialization_status
 * @property string|null $initialization_error
 * @property Carbon|null $initialized_at
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read GitHubInstallation|null $githubInstallation
 * @property-read GitHubInstallation|null $gitOpsGitHubInstallation
 * @property-read ProjectManifest|null $manifest
 * @property-read Collection<int, ProjectSecretRevision> $secretRevisions
 */
#[Fillable(['team_id', 'github_installation_id', 'gitops_github_installation_id', 'name', 'slug', 'framework', 'repository', 'repository_id', 'default_branch', 'auto_deploy', 'gitops_repository_mode', 'gitops_repository', 'gitops_repository_id', 'gitops_branch', 'gitops_path', 'gitops_publish_mode', 'initialization_status', 'initialization_error', 'initialized_at', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    public const InitializationPending = 'pending';

    public const InitializationInitializing = 'initializing';

    public const InitializationReady = 'ready';

    public const InitializationFailed = 'failed';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_branch' => 'main',
        'auto_deploy' => true,
        'gitops_repository_mode' => GitOpsRepositoryMode::CoLocated->value,
        'gitops_path' => 'deploy/k8s',
        'gitops_publish_mode' => GitOpsPublishMode::Direct->value,
        'initialization_status' => self::InitializationPending,
    ];

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
     * Get the GitHub installation used by a separate GitOps repository.
     *
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function gitOpsGitHubInstallation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'gitops_github_installation_id');
    }

    /**
     * Get the manifest workspace for this project.
     *
     * @return HasOne<ProjectManifest, $this>
     */
    public function manifest(): HasOne
    {
        return $this->hasOne(ProjectManifest::class);
    }

    /**
     * Get Vault secret write audit metadata for this project.
     *
     * @return HasMany<ProjectSecretRevision, $this>
     */
    public function secretRevisions(): HasMany
    {
        return $this->hasMany(ProjectSecretRevision::class);
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
            'gitops_repository_mode' => GitOpsRepositoryMode::class,
            'gitops_publish_mode' => GitOpsPublishMode::class,
            'initialized_at' => 'datetime',
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
