<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function view(User $actor, Position $model): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function update(User $actor, Position $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function delete(User $actor, Position $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }
}
