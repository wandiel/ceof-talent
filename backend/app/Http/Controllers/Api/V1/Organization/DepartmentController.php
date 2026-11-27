<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Domain\Organization\Actions\DeleteOrganizationRecord;
use App\Domain\Organization\Actions\SaveOrganizationRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DepartmentController extends Controller
{
    use FiltersOrganizationRecords;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Department::class);

        $query = Department::query()->withCount(['positions', 'users']);
        $perPage = $this->applyCommonFilters($request, $query, ['name', 'code'], ['name', 'code', 'created_at']);

        return DepartmentResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(DepartmentRequest $request, SaveOrganizationRecord $save): JsonResponse
    {
        $department = $save->handle(new Department, $request->validated(), 'department');

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        $this->authorize('view', $department);

        return new DepartmentResource($department->loadCount(['positions', 'users']));
    }

    public function update(DepartmentRequest $request, Department $department, SaveOrganizationRecord $save): DepartmentResource
    {
        return new DepartmentResource($save->handle($department, $request->validated(), 'department'));
    }

    public function destroy(Department $department, DeleteOrganizationRecord $delete): Response
    {
        $this->authorize('delete', $department);
        $delete->handle($department, 'department', ['positions' => 'cargos', 'users' => 'usuários']);

        return response()->noContent();
    }
}
