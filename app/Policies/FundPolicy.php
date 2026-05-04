<?php

namespace App\Policies;

use App\Models\Fund;
use App\Models\User;

class FundPolicy
{
    public function view(User $user, Fund $fund): bool
    {
        if ($user->id === $fund->user_id) {
            return true;
        }

        if ($fund->family_id && $user->family_id === $fund->family_id) {
            return true;
        }

        return false;
    }

    public function update(User $user, Fund $fund): bool
    {
        if ($user->id === $fund->user_id) {
            return true;
        }

        if ($fund->family_id && $user->family_id === $fund->family_id) {
            return true;
        }

        return false;
    }

    public function delete(User $user, Fund $fund): bool
    {
        if ($user->id === $fund->user_id) {
            return true;
        }

        if ($fund->family_id && $user->family_id === $fund->family_id && $user->can_manage_family) {
            return true;
        }

        return false;
    }
}
