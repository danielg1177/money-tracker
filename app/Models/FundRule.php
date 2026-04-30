<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fund_id',
        'name',
        'order',
        'allocation_type',
        'amount',
        'allocation_base',
        'is_active',
    ];

    protected $casts = [
        'order' => 'integer',
        'amount' => 'decimal:2',
        'is_active' => 'bool',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
