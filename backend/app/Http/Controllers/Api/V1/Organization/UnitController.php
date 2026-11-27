<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Domain\Organization\Actions\DeleteOrganizationRecord;
use App\Domain\Organization\Actions\SaveOrganizationRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UnitController extends Controller
{
    use FiltersOrganizationRecords;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Unit::class);

        $query = Unit::query()->withCount('users');
        $perPage = $this->applyCommonFilters($request, $query, ['name', 'code', 'city'], ['name', 'code', 'city', 'created_at']);

        return UnitResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(UnitRequest $request, SaveOrganizationRecord $save): JsonResponse
    {
        $unit = $save->handle(new Unit, $request->validated(), 'unit');

        return (new UnitResource($unit))->response()->setStatusCode(201);
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);

        return new UnitResource($unit->loadCount('users'));
    }

    public function update(UnitRequest $request, Unit $unit, SaveOrganizationRecord $save): UnitResource
    {
        return new UnitResource($save->handle($unit, $request->validated(), 'unit'));
    }

    public function destroy(Unit $unit, DeleteOrganizationRecord $delete): Response
    {
        $this->authorize('delete', $unit);
        $delete->handle($unit, 'unit', ['users' => 'usuários']);

        return response()->noContent();
    }
}
