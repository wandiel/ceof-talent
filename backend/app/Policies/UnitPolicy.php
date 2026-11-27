<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function view(User $actor, Unit $model): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function update(User $actor, Unit $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function delete(User $actor, Unit $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }
}
