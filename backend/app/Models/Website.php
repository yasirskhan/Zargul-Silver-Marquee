<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    use HasUuids;

    protected $attributes = ['is_active' => true];

    // Identity/tenant and theme publication settings are not mass assignable.
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['theme_tokens' => 'array', 'is_active' => 'boolean'];
    }

    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(SitePage::class);
    }
}
