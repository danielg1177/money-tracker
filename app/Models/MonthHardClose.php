<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthHardClose extends Model
{
    protected $fillable = [
        'family_id',
        'year',
        'month',
        'closed_at',
        'closed_by_user_id',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'year' => 'integer',
        'month' => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
