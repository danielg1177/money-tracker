<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'name',
        'icon',
        'is_income',
        'is_expense',
        'is_split_default',
        'split_default',
    ];

    protected $casts = [
        'is_income' => 'bool',
        'is_expense' => 'bool',
        'is_split_default' => 'bool',
        'split_default' => 'array',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
