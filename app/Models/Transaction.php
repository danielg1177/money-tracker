<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'advance_fund_id',
        'is_non_necessity',
        'is_borrow',
        'is_debt_payment',
        'debt_id',
        'paid_by_user_id',
        'is_closeout_initiated',
        'mirror_transaction_id',
        'plaid_transaction_id',
        'import_source',
        'plaid_pending_import_id',
        'is_repayment',
        'is_repaid',
        'is_repayment_mirror',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'is_split' => 'bool',
        'split_data' => 'array',
        'is_borrow' => 'bool',
        'is_debt_payment' => 'bool',
        'is_closeout_initiated' => 'bool',
        'is_non_necessity' => 'bool',
        'is_repayment' => 'bool',
        'is_repaid' => 'bool',
        'is_repayment_mirror' => 'bool',
        'advance_fund_id' => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
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

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function mirrorTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mirror_transaction_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function plaidPendingImport(): HasOne
    {
        return $this->hasOne(PlaidPendingImport::class, 'transaction_id');
    }

    public function repaymentLinks(): HasMany
    {
        return $this->hasMany(TransactionRepaymentLink::class, 'repayment_transaction_id');
    }

    public function repaidByLink(): HasOne
    {
        return $this->hasOne(TransactionRepaymentLink::class, 'repaid_transaction_id');
    }

    public function mirrorRepaymentLink(): HasOne
    {
        return $this->hasOne(TransactionRepaymentLink::class, 'mirror_transaction_id');
    }
}
