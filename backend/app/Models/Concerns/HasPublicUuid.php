<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Chave primária bigint interna + coluna "uuid" pública.
 * A API e as URLs usam apenas o UUID, evitando enumeração de registros.
 */
trait HasPublicUuid
{
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
