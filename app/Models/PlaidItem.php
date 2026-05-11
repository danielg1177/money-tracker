<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaidItem extends Model
{
    protected $fillable = [
        'user_id',
        'item_id',
        'access_token',
        'institution_id',
        'institution_name',
        'account_type',
        'transactions_cursor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pendingImports(): HasMany
    {
        return $this->hasMany(PlaidPendingImport::class, 'plaid_item_id');
    }

    /**
     * Scope Link model binding to the authenticated user.
     *
     * @param  mixed  $value
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        $field ??= $this->getRouteKeyName();

        return self::query()
            ->where($field, $value)
            ->where('user_id', auth()->id())
            ->firstOrFail();
    }
}
