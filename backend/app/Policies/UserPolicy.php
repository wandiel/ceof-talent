<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    /** Ninguém desativa a si mesmo (evita perder o último acesso administrativo por engano). */
    public function changeStatus(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value) && ! $actor->is($user);
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value) && ! $actor->is($user);
    }
}
