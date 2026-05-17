<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionRepaymentLink extends Model
{
    protected $fillable = [
        'repayment_transaction_id',
        'repaid_transaction_id',
        'mirror_transaction_id',
        'repaid_user_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'mirror_transaction_id' => 'integer',
    ];

    public function repaymentTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'repayment_transaction_id');
    }

    public function repaidTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'repaid_transaction_id');
    }

    public function mirrorTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'mirror_transaction_id');
    }

    public function repaidUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaid_user_id');
    }
}
