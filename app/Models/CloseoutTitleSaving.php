<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloseoutTitleSaving extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'year',
        'month',
        'title',
        'amount',
        'rule_id',
        'is_completed',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'year' => 'integer',
        'month' => 'integer',
        'is_completed' => 'bool',
        'completed_at' => 'datetime',
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
