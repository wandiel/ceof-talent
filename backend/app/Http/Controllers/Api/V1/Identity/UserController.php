<?php

namespace App\Http\Controllers\Api\V1\Identity;

use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Actions\ResetUserPassword;
use App\Domain\Identity\Actions\SaveUser;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'last_login_at', 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(Role::values())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'department_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = User::query()->with(['roles', 'unit', 'department']);

        if ($search = $filters['search'] ?? null) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
        }

        if ($role = $filters['role'] ?? null) {
            $query->role($role);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }

        if ($department = $filters['department_id'] ?? null) {
            $query->whereHas('department', fn ($q) => $q->where('uuid', $department));
        }

        $this->applySort($query, $filters['sort'] ?? 'name');

        return UserResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(UserRequest $request, SaveUser $save): JsonResponse
    {
        $user = $save->handle($request->user(), $request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load(['roles', 'unit', 'department']));
    }

    public function update(UserRequest $request, User $user, SaveUser $save): UserResource
    {
        return new UserResource($save->handle($request->user(), $request->validated(), $user));
    }

    public function updateStatus(Request $request, User $user, ChangeUserStatus $changeStatus): UserResource
    {
        $this->authorize('changeStatus', $user);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return new UserResource($changeStatus->handle($user, $data['is_active'])->load(['roles', 'unit', 'department']));
    }

    public function resetPassword(Request $request, User $user, ResetUserPassword $reset): JsonResponse
    {
        $this->authorize('resetPassword', $user);
        $data = $request->validate(
            ['password' => ['required', 'string', PasswordPolicy::rule()]],
            attributes: ['password' => 'senha provisória'],
        );

        $reset->handle($user, $data['password']);

        return response()->json(['message' => 'Senha provisória definida. O usuário deverá trocá-la no próximo acesso.']);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<User> $query */
    private function applySort($query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            $column = 'name';
        }

        $query->orderBy($column, $direction)->orderBy('id');
    }
}
