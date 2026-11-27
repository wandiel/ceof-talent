<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** Gera o arquivo do relatório em CSV, XLSX ou PDF. */
final class ReportExporter
{
    /** Limite de linhas por formato (o PDF fica ilegível e pesado com mais). */
    public const LIMITS = ['csv' => 100000, 'xlsx' => 100000, 'pdf' => 3000];

    /**
     * @param  array{title: string, description: string, columns: list<array{key: string, label: string, type: string}>}  $report
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function export(string $format, string $key, array $report, iterable $rows, ReportFilters $filters): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rel');
        $limit = self::LIMITS[$format];

        $count = match ($format) {
            'csv' => $this->csv($path, $report['columns'], $rows, $limit),
            'xlsx' => $this->xlsx($path, $report, $rows, $filters, $limit),
            'pdf' => $this->pdf($path, $report, $rows, $filters, $limit),
        };

        $name = 'relatorio-'.str_replace('_', '-', $key).'-'.now()->format('Ymd-Hi').'.'.$format;
        $mime = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'][$format];

        return response()->download($path, $name, ['Content-Type' => $mime, 'X-Report-Rows' => (string) $count], ResponseHeaderBag::DISPOSITION_ATTACHMENT)
            ->deleteFileAfterSend();
    }

    /** Valor como texto para a tela, o CSV e o PDF, no padrão brasileiro. */
    public static function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'number' => number_format((float) $value, 0, ',', '.'),
            'decimal' => number_format((float) $value, (float) $value === floor((float) $value) ? 0 : 1, ',', '.'),
            'percent' => number_format((float) $value, 1, ',', '.').'%',
            'date' => CarbonImmutable::parse($value)->format('d/m/Y'),
            'datetime' => CarbonImmutable::parse($value)->format('d/m/Y H:i'),
            default => (string) $value,
        };
    }

    /** Neutraliza fórmulas (=, +, -, @) em textos, para a planilha não executar nada ao abrir. */
    private static function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function csv(string $path, array $columns, iterable $rows, int $limit): int
    {
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre com acentos corretos
        fputcsv($out, array_column($columns, 'label'), ';', '"', '');
        $n = 0;
        foreach ($rows as $row) {
            if ($n++ >= $limit) {
                break;
            }
            fputcsv($out, array_map(fn ($c) => self::safeText(self::format($row[$c['key']] ?? null, $c['type'])), $columns), ';', '"', '');
        }
        fclose($out);

        return min($n, $limit);
    }

    private function xlsx(string $path, array $report, iterable $rows, ReportFilters $filters, int $limit): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $report['title']), 0, 31));

        $bold = (new Style)->setFontBold();
        $header = (new Style)->setFontBold()->setBackgroundColor('DBEAFE');
        $writer->addRow(Row::fromValues([$report['title']], (new Style)->setFontBold()->setFontSize(14)));
        foreach ($filters->describe() as $f) {
            $writer->addRow(Row::fromValues([$f['label'].': '.$f['value']]));
        }
        $writer->addRow(Row::fromValues(['Gerado em '.now()->format('d/m/Y H:i').' por '.$filters->user->name]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(array_column($report['columns'], 'label'), $header));

        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        $dateTimeStyle = (new Style)->setFormat('dd/mm/yyyy hh:mm');
        $percentStyle = (new Style)->setFormat('0.0"%"');
        $n = 0;
        foreach ($rows as $row) {
            if ($n++ >= $limit) {
                break;
            }
            $cells = [];
            foreach ($report['columns'] as $c) {
                $v = $row[$c['key']] ?? null;
                $cells[] = match (true) {
                    $v === null || $v === '' => Cell::fromValue(''),
                    $c['type'] === 'date' => Cell::fromValue(new \DateTimeImmutable($v), $dateStyle),
                    $c['type'] === 'datetime' => Cell::fromValue(new \DateTimeImmutable($v), $dateTimeStyle),
                    $c['type'] === 'percent' => Cell::fromValue((float) $v, $percentStyle),
                    in_array($c['type'], ['number', 'decimal'], true) => Cell::fromValue(is_int($v) ? $v : (float) $v),
                    default => Cell::fromValue(self::safeText((string) $v)),
                };
            }
            $writer->addRow(new Row($cells));
        }
        if ($n === 0) {
            $writer->addRow(Row::fromValues(['Nenhum registro encontrado com esses filtros.'], $bold));
        }
        $writer->close();

        return min($n, $limit);
    }

    private function pdf(string $path, array $report, iterable $rows, ReportFilters $filters, int $limit): int
    {
        $list = [];
        $truncated = false;
        foreach ($rows as $row) {
            if (count($list) >= $limit) {
                $truncated = true;
                break;
            }
            $list[] = array_map(fn ($c) => self::format($row[$c['key']] ?? null, $c['type']), $report['columns']);
        }

        $html = view('reports.pdf', [
            'report' => $report,
            'rows' => $list,
            'filters' => $filters->describe(),
            'generatedAt' => now()->format('d/m/Y H:i'),
            'generatedBy' => $filters->user->name,
            'truncated' => $truncated,
            'limit' => $limit,
            'logo' => is_file($logo = resource_path('images/logo-ceof.png')) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', count($report['columns']) > 5 ? 'landscape' : 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.4, 0.45, 0.5]);
        file_put_contents($path, $dompdf->output());

        return count($list);
    }
}
