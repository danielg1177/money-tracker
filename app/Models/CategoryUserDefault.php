<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryUserDefault extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'user_id',
        'advance_fund_id',
        'is_non_necessity_default',
    ];

    protected $casts = [
        'advance_fund_id' => 'integer',
        'is_non_necessity_default' => 'bool',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advanceFund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'advance_fund_id');
    }
}
