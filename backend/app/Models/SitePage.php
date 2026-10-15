<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePage extends Model
{
    use HasUuids;

    // Tenant, website, draft version and publication pointer are service-managed.
    protected $fillable = ['slug', 'title'];

    protected function casts(): array
    {
        return ['draft_revision' => 'integer'];
    }

    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SitePageRevision::class, 'page_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(SitePageRevision::class, 'published_revision_id');
    }
}
