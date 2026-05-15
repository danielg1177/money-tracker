<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidMerchantRule extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_key',
        'category_id',
        'type',
        'fund_id',
        'advance_fund_id',
        'is_non_necessity',
        'is_split',
        'description',
        'is_debt_payment',
        'debt_id',
        'split_data',
        'confirmation_count',
        'total_seen_count',
        'action',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_non_necessity' => 'bool',
            'is_split' => 'bool',
            'is_debt_payment' => 'bool',
            'split_data' => 'array',
            'confirmation_count' => 'integer',
            'total_seen_count' => 'integer',
        ];
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

    public function advanceFund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'advance_fund_id');
    }

    public function confidenceScore(): float
    {
        if ($this->total_seen_count <= 0) {
            return 0.0;
        }

        return $this->confirmation_count / $this->total_seen_count;
    }

    public function isAutoCreateEligible(): bool
    {
        return $this->confirmation_count >= 3 && $this->confidenceScore() >= 0.80;
    }

    public static function normalizeKey(string $name): string
    {
        $lower = strtolower($name);
        $lettersDigitsSpaces = preg_replace('/[^a-z0-9 ]/', '', $lower) ?? '';
        $collapsed = preg_replace('/\s+/', ' ', $lettersDigitsSpaces) ?? '';

        return trim($collapsed);
    }
}
