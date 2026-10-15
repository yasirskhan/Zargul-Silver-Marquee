<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyMembership extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'role', 'is_active', 'can_edit_site', 'can_publish_site',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'can_edit_site' => 'boolean',
            'can_publish_site' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hallAssignments(): HasMany
    {
        return $this->hasMany(HallAssignment::class, 'membership_id');
    }
}
