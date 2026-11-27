<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exclui um registro organizacional somente se nada depender dele.
 * Registros em uso devem ser desativados, preservando o histórico.
 */
class DeleteOrganizationRecord
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, string> $dependencies relação => descrição legível */
    public function handle(Model $model, string $entity, array $dependencies): void
    {
        foreach ($dependencies as $relation => $label) {
            if ($model->{$relation}()->exists()) {
                throw ValidationException::withMessages([
                    'record' => "Não é possível excluir: existem {$label} vinculados. Desative o registro para preservar o histórico.",
                ])->status(409);
            }
        }

        DB::transaction(function () use ($model, $entity) {
            $snapshot = $model->attributesToArray();
            $model->delete();
            $this->audit->log("{$entity}.deleted", $model, old: $snapshot);
        });
    }
}
