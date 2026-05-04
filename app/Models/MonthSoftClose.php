<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthSoftClose extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'year',
        'month',
        'closed_at',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
