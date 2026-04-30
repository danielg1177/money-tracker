<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fundRules(): HasMany
    {
        return $this->hasMany(FundRule::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FundMovement::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }
}
