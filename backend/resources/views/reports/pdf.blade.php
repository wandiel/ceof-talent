<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{{ $report['title'] }}</title>
<style>
  @page { margin: 26px 28px 44px 28px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1e293b; }
  .head { width: 100%; border-bottom: 2px solid #1d4ed8; padding-bottom: 8px; margin-bottom: 10px; }
  .head td { vertical-align: middle; }
  .logo { height: 34px; }
  h1 { font-size: 15px; margin: 0; color: #0f172a; }
  .sub { color: #64748b; font-size: 8.5px; margin-top: 2px; }
  .meta { text-align: right; color: #475569; font-size: 8px; line-height: 1.5; }
  .filters { margin-bottom: 10px; color: #334155; }
  .filters span { display: inline-block; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 3px; padding: 2px 6px; margin-right: 4px; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th { background: #1d4ed8; color: #fff; text-align: left; font-weight: bold; padding: 4px 5px; font-size: 8px; }
  table.data td { padding: 3px 5px; border-bottom: 1px solid #e2e8f0; }
  table.data tr:nth-child(even) td { background: #f8fafc; }
  .num { text-align: right; }
  .empty { padding: 20px; text-align: center; color: #64748b; }
  .note { margin-top: 8px; color: #b45309; }
  .foot { position: fixed; bottom: -30px; left: 0; color: #94a3b8; font-size: 7px; }
</style>
</head>
<body>
<table class="head"><tr>
  <td style="width: 90px">@if($logo)<img class="logo" src="{{ $logo }}" alt="CEOF">@endif</td>
  <td><h1>{{ $report['title'] }}</h1><div class="sub">{{ $report['description'] }}</div></td>
  <td class="meta">CEOF TALENT<br>Gerado em {{ $generatedAt }}<br>por {{ $generatedBy }}</td>
</tr></table>

<div class="filters">@foreach($filters as $f)<span><strong>{{ $f['label'] }}:</strong> {{ $f['value'] }}</span>@endforeach</div>

<table class="data">
  <thead><tr>@foreach($report['columns'] as $c)<th class="{{ in_array($c['type'], ['number', 'decimal', 'percent']) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
  <tbody>
  @forelse($rows as $row)
    <tr>@foreach($report['columns'] as $i => $c)<td class="{{ in_array($c['type'], ['number', 'decimal', 'percent']) ? 'num' : '' }}">{{ $row[$i] }}</td>@endforeach</tr>
  @empty
    <tr><td class="empty" colspan="{{ count($report['columns']) }}">Nenhum registro encontrado com esses filtros.</td></tr>
  @endforelse
  </tbody>
</table>
<p class="sub">{{ count($rows) }} {{ count($rows) === 1 ? 'registro' : 'registros' }}</p>
@if($truncated)<p class="note">O PDF mostra os primeiros {{ number_format($limit, 0, ',', '.') }} registros. Exporte em XLSX ou CSV para ver todos.</p>@endif
<div class="foot">Documento interno do Hospital de Olhos CEOF. Contém dados pessoais protegidos pela LGPD: não compartilhe fora da finalidade de recrutamento.</div>
</body>
</html>
