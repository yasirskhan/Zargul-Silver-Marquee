<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = ['company_id', 'membership_id', 'hall_id'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CompanyMembership::class, 'membership_id');
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }
}
