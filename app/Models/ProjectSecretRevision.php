<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit metadata for a Vault write. Secret keys and values are never persisted here.
 */
class ProjectSecretRevision extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'vault_version',
        'updated_by',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vault_version' => 'integer'];
    }
}
