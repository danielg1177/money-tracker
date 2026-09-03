<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyCloseoutRule extends Model
{
    use HasFactory;

    public const StageSurplus = 'surplus';

    public const StageRemainingAfterCharity = 'remaining_after_charity';

    protected $fillable = [
        'family_id',
        'name',
        'order',
        'is_active',
        'stage',
        'allocation_type',
        'amount',
        'destination_type',
        'destination_id',
        'destination_title',
        'closeout_expense_category_id',
    ];

    protected $casts = [
        'order' => 'integer',
        'is_active' => 'bool',
        'amount' => 'decimal:2',
        'destination_id' => 'integer',
        'closeout_expense_category_id' => 'integer',
    ];

    /**
     * @return list<string>
     */
    public static function stages(): array
    {
        return [self::StageSurplus, self::StageRemainingAfterCharity];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function closeoutExpenseCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'closeout_expense_category_id');
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = auth()->user();

        if (! $user?->family_id) {
            return null;
        }

        return static::query()
            ->where('family_id', $user->family_id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
