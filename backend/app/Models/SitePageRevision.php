<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SitePageRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    // Never allow mass assignment of revision history. The transactional
    // publishing service creates it and PostgreSQL triggers guard the history.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['content' => 'array', 'revision_number' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Website history cannot be updated.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Website history cannot be deleted.');
        });
    }

    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'page_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
