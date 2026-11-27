<?php

namespace App\Http\Resources;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** Inclui a lista efetiva de permissões (usado em /auth/me). */
    public bool $withPermissions = false;

    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? ['id' => $this->unit->uuid, 'name' => $this->unit->name] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->uuid, 'name' => $this->department->name] : null),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'name' => $role->name,
                'label' => Role::tryFrom($role->name)?->label() ?? $role->name,
            ])->values()),
            'permissions' => $this->when($this->withPermissions, fn () => $this->getAllPermissions()->pluck('name')->sort()->values()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
