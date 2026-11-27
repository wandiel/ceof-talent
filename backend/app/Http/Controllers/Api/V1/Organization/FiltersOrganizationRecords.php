<?php

namespace App\Http\Controllers\Api\V1\Organization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Filtros comuns às listagens da estrutura organizacional. */
trait FiltersOrganizationRecords
{
    /**
     * Aplica busca textual, status e ordenação. Retorna o tamanho da página.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $searchable
     * @param  list<string>  $sortable
     */
    protected function applyCommonFilters(Request $request, Builder $query, array $searchable, array $sortable, string $defaultSort = 'name'): int
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        if ($search = $filters['search'] ?? null) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $q) use ($searchable, $term) {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'ilike', $term);
                }
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }

        $sort = $filters['sort'] ?? $defaultSort;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy(in_array($column, $sortable, true) ? $column : $defaultSort, $direction)->orderBy('id');

        return (int) ($filters['per_page'] ?? 15);
    }
}
