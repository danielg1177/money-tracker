<?php

namespace App\Policies;

use App\Models\Debt;
use App\Models\User;

class DebtPolicy
{
    public function view(User $user, Debt $debt): bool
    {
        return $user->family_id === $debt->family_id &&
               ($user->id === $debt->debtor_id || $user->id === $debt->creditor_id);
    }
}
