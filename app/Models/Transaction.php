<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'user_id',
        'category_id',
        'type',
        'amount',
        'description',
        'transaction_date',
        'is_split',
        'split_data',
        'fund_id',
        'is_borrow',
        'is_debt_payment',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'is_split' => 'bool',
        'split_data' => 'array',
        'is_borrow' => 'bool',
        'is_debt_payment' => 'bool',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }
}
