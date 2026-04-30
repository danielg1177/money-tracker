<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'fund_id',
        'user_id',
        'type',
        'amount',
        'transaction_id',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
