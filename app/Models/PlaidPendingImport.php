<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidPendingImport extends Model
{
    protected $fillable = [
        'user_id',
        'plaid_item_id',
        'plaid_transaction_id',
        'plaid_account_id',
        'amount',
        'date',
        'merchant_name',
        'raw_name',
        'suggested_category_id',
        'suggested_type',
        'suggested_fund_id',
        'suggested_advance_fund_id',
        'suggested_is_non_necessity',
        'confidence_score',
        'status',
        'transaction_id',
        'raw_payload',
        'is_transfer',
        'plaid_category_primary',
        'plaid_category_detailed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'raw_payload' => 'array',
            'suggested_is_non_necessity' => 'bool',
            'confidence_score' => 'decimal:4',
            'is_transfer' => 'bool',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class, 'plaid_item_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isAutoCreateEligible(): bool
    {
        return false;
    }

    /**
     * @param  mixed  $value
     */
    public function resolveRouteBinding($value, $field = null): static
    {
        $field ??= $this->getRouteKeyName();

        return static::query()
            ->where($field, $value)
            ->where('user_id', auth()->id())
            ->firstOrFail();
    }
}
