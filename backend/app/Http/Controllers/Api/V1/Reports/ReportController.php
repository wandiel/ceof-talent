<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Linhas mostradas na tela; o arquivo exportado traz todas. */
    private const PREVIEW = 500;

    public function __construct(private readonly ReportCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can(Permission::ReportsView->value), 403);

        return response()->json([
            'data' => collect($this->catalog->all())->map(fn ($r, $key) => ['key' => $key, 'group' => $r['group'], 'title' => $r['title'], 'description' => $r['description']])->values(),
            'meta' => [
                'can_export' => $user->can(Permission::ReportsExport->value),
                'formats' => array_keys(ReportExporter::LIMITS),
                ...ReportFilters::options($user),
            ],
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        abort_unless($request->user()->can(Permission::ReportsView->value), 403);
        $definition = $this->catalog->get($report);
        $filters = ReportFilters::fromRequest($request);
        $rows = $this->catalog->rows($report, new ReportScope($filters));

        $preview = [];
        $total = 0;
        foreach ($rows as $row) {
            if ($total++ < self::PREVIEW) {
                $preview[] = $row;
            }
        }

        return response()->json([
            'data' => [
                'key' => $report,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'columns' => $definition['columns'],
                'rows' => $preview,
            ],
            'meta' => ['total' => $total, 'shown' => count($preview), 'filters' => $filters->toArray(), 'filters_label' => $filters->describe()],
        ]);
    }

    public function export(Request $request, string $report, string $format, ReportExporter $exporter, AuditLogger $audit): BinaryFileResponse
    {
        abort_unless($request->user()->can(Permission::ReportsExport->value), 403);
        abort_unless(array_key_exists($format, ReportExporter::LIMITS), 404);
        $definition = $this->catalog->get($report);
        $filters = ReportFilters::fromRequest($request);

        $response = $exporter->export($format, $report, $definition, $this->catalog->rows($report, new ReportScope($filters)), $filters);

        // Registro de quem exportou o quê (sem os dados exportados).
        $audit->log('report.exported', null, "Relatório \"{$definition['title']}\" exportado em ".strtoupper($format).'.', context: [
            'report' => $report,
            'format' => $format,
            'rows' => (int) $response->headers->get('X-Report-Rows'),
            'filters' => $filters->toArray(),
        ]);

        return $response;
    }
}
