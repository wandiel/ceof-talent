<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Reports\DashboardService;
use App\Domain\Reports\ReportFilters;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        abort_unless($request->user()->can(Permission::DashboardView->value), 403);
        $filters = ReportFilters::fromRequest($request);

        return response()->json([
            'data' => $dashboard->build($filters),
            'meta' => [
                'filters' => $filters->toArray(),
                'generated_at' => now()->toIso8601String(),
                'can_view_reports' => $request->user()->can(Permission::ReportsView->value),
                ...ReportFilters::options($request->user()),
            ],
        ]);
    }
}
