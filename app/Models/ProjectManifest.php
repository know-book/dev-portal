<?php

namespace App\Models;

use Database\Factories\ProjectManifestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectManifest extends Model
{
    /** @use HasFactory<ProjectManifestFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'preset_key',
        'preset_version',
        'variables',
        'base_hash',
        'lock_version',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lock_version' => 1,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ProjectManifestPatch, $this>
     */
    public function patches(): HasMany
    {
        return $this->hasMany(ProjectManifestPatch::class);
    }

    /**
     * @return HasMany<ProjectManifestRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectManifestRevision::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }
}
