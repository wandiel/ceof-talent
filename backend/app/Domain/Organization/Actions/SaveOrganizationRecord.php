<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou atualiza um registro da estrutura organizacional (unidade,
 * departamento ou cargo) de forma transacional e auditada.
 */
class SaveOrganizationRecord
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function handle(Model $model, array $attributes, string $entity): Model
    {
        return DB::transaction(function () use ($model, $attributes, $entity) {
            $creating = ! $model->exists;
            $original = $model->getAttributes();

            $model->fill($attributes)->save();

            if ($creating) {
                // Carrega valores padrão definidos no banco (ex.: is_active).
                $model->refresh();
            }

            if ($creating) {
                $this->audit->log("{$entity}.created", $model, new: $model->only(array_keys($attributes)));
            } else {
                $this->audit->logChanges("{$entity}.updated", $model, $original);
            }

            return $model;
        });
    }
}
