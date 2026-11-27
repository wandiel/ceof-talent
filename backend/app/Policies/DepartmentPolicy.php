<?php

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function view(User $actor, Department $model): bool
    {
        return $actor->can(Permission::OrganizationView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function update(User $actor, Department $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }

    public function delete(User $actor, Department $model): bool
    {
        return $actor->can(Permission::OrganizationManage->value);
    }
}
