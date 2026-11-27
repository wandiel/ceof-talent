<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Registra eventos na trilha de auditoria.
 *
 * Campos sensíveis (senhas, tokens) nunca são gravados: são removidos de
 * old_values/new_values antes da persistência.
 */
class AuditLogger
{
    /** @var list<string> */
    private const REDACTED = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'cpf', 'cpf_hash'];

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $context
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        ?array $context = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? request()->user()?->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description ? Str::limit($description, 250) : null,
            'old_values' => $this->clean($old),
            'new_values' => $this->clean($new),
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
        ]);
    }

    /**
     * Registra as alterações de um model já salvo (usar logo após save()).
     *
     * @param  array<string, mixed>  $original  atributos antes da alteração
     */
    public function logChanges(string $event, Model $model, array $original, ?string $description = null): ?AuditLog
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);

        if ($changes === []) {
            return null;
        }

        return $this->log(
            $event,
            $model,
            $description,
            old: Arr::only($original, array_keys($changes)),
            new: $changes,
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[protegido]';
            }
        }

        return $values;
    }
}
