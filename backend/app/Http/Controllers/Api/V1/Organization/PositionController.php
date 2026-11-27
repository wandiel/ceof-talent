<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Domain\Organization\Actions\DeleteOrganizationRecord;
use App\Domain\Organization\Actions\SaveOrganizationRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PositionController extends Controller
{
    use FiltersOrganizationRecords;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Position::class);

        $request->validate(['department_id' => ['nullable', 'uuid']]);

        $query = Position::query()->with('department')
            ->when($request->input('department_id'), fn ($q, $uuid) => $q->whereHas('department', fn ($d) => $d->where('uuid', $uuid)));

        $perPage = $this->applyCommonFilters($request, $query, ['title', 'cbo_code'], ['title', 'created_at'], 'title');

        return PositionResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(PositionRequest $request, SaveOrganizationRecord $save): JsonResponse
    {
        $position = $save->handle(new Position, $this->attributes($request), 'position');

        return (new PositionResource($position->load('department')))->response()->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        $this->authorize('view', $position);

        return new PositionResource($position->load('department'));
    }

    public function update(PositionRequest $request, Position $position, SaveOrganizationRecord $save): PositionResource
    {
        return new PositionResource($save->handle($position, $this->attributes($request), 'position')->load('department'));
    }

    public function destroy(Position $position, DeleteOrganizationRecord $delete): Response
    {
        $this->authorize('delete', $position);
        // Vagas (Fase 6) passarão a ser verificadas aqui.
        $delete->handle($position, 'position', []);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function attributes(PositionRequest $request): array
    {
        $data = $request->validated();
        $data['department_id'] = isset($data['department_id'])
            ? Department::query()->where('uuid', $data['department_id'])->value('id')
            : null;

        if (isset($data['cbo_code']) && ! str_contains($data['cbo_code'], '-')) {
            $data['cbo_code'] = substr($data['cbo_code'], 0, 4).'-'.substr($data['cbo_code'], 4);
        }

        return $data;
    }
}
