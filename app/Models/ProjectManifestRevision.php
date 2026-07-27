<?php

namespace App\Models;

use Database\Factories\ProjectManifestRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $published_at
 */
class ProjectManifestRevision extends Model
{
    /** @use HasFactory<ProjectManifestRevisionFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusPublished = 'published';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_manifest_id',
        'revision_number',
        'patch_snapshot',
        'compiled_hash',
        'status',
        'created_by',
        'published_at',
        'git_commit_sha',
        'git_repository',
        'git_branch',
        'git_path',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::StatusDraft,
    ];

    /**
     * @return BelongsTo<ProjectManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ProjectManifest::class, 'project_manifest_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'patch_snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
