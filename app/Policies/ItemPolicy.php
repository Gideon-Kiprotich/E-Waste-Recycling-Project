<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    public function view(User $user, Item $item): bool
    {
        return $user->canSignIn() && $user->role() === Role::Consumer && $item->owner_user_id === $user->id;
    }

    public function update(User $user, Item $item): bool
    {
        return $this->view($user, $item) && $item->processing_state === 'registered';
    }
}
