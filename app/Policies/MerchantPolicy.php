<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\User;

class MerchantPolicy
{
    public function view(User $user, Merchant $merchant): bool
    {
        return $user->merchants()->where('merchant_id', $merchant->id)->exists();
    }

    /**
     * Connecting/disconnecting stores and changing capability config are
     * both treated as "update" — deliberately more restrictive than view,
     * since both touch things that affect real orders and money.
     */
    public function update(User $user, Merchant $merchant): bool
    {
        return $user->hasRoleOn($merchant, 'owner', 'admin');
    }
}
