<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'family_id', 'role', 'is_admin', 'bank_balance_enabled', 'bank_balance', 'bank_balance_set_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Expose admin flag in JSON for the SPA (matches Gate `admin` / `role === 'admin'`).
     *
     * @var list<string>
     */
    protected $appends = [
        'is_admin',
        'is_head_of_household',
        'can_manage_family',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'bool',
            'bank_balance_enabled' => 'bool',
            'bank_balance' => 'decimal:2',
            'bank_balance_set_at' => 'date',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function fundMovements(): HasMany
    {
        return $this->hasMany(FundMovement::class);
    }

    public function debtsOwed(): HasMany
    {
        return $this->hasMany(Debt::class, 'debtor_id');
    }

    public function debtsOwedTo(): HasMany
    {
        return $this->hasMany(Debt::class, 'creditor_id');
    }

    public function monthSoftCloses(): HasMany
    {
        return $this->hasMany(MonthSoftClose::class);
    }

    #[Attribute]
    protected function isAdmin(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->attributes['is_admin'],
        );
    }

    #[Attribute]
    protected function isHeadOfHousehold(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->role === 'head_of_household',
        );
    }

    #[Attribute]
    protected function canManageFamily(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->role === 'head_of_household' || (bool) ($this->attributes['is_admin'] ?? false),
        );
    }
}
