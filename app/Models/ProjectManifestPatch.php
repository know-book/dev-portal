<?php

namespace App\Models;

use Database\Factories\ProjectManifestPatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectManifestPatch extends Model
{
    /** @use HasFactory<ProjectManifestPatchFactory> */
    use HasFactory;

    public const OperationReplace = 'replace';

    public const OperationDelete = 'delete';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_manifest_id',
        'path',
        'operation',
        'content',
        'base_content_hash',
        'edited_by',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'operation' => self::OperationReplace,
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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
