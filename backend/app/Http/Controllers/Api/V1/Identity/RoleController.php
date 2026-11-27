<?php

namespace App\Http\Controllers\Api\V1\Identity;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catálogo (somente leitura) de perfis e permissões. */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permission::RolesView->value) || $request->user()->can(Permission::UsersManage->value),
            403,
        );

        return response()->json([
            'data' => array_map(fn (Role $role) => [
                'name' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'permissions' => array_map(fn (Permission $p) => [
                    'name' => $p->value,
                    'label' => $p->label(),
                    'group' => $p->group(),
                ], $role->permissions()),
            ], Role::cases()),
        ]);
    }
}
