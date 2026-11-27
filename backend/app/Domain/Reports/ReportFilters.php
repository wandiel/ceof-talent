<?php

namespace App\Domain\Reports;

use App\Domain\Candidates\Enums\ProfessionalArea;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Filtros comuns do dashboard e dos relatórios, já ajustados ao que o usuário pode ver:
 * quem não trabalha com todos os departamentos fica preso ao próprio departamento.
 */
final class ReportFilters
{
    public function __construct(
        public readonly User $user,
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
        public readonly ?Department $department = null,
        public readonly ?Unit $unit = null,
        public readonly ?ProfessionalArea $area = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department' => ['nullable', 'uuid', Rule::exists('departments', 'uuid')],
            'unit' => ['nullable', 'uuid', Rule::exists('units', 'uuid')],
            'area' => ['nullable', Rule::enum(ProfessionalArea::class)],
        ], [], ['from' => 'data inicial', 'to' => 'data final', 'department' => 'departamento', 'unit' => 'unidade', 'area' => 'área']);

        /** @var User $user */
        $user = $request->user();
        $tz = config('app.timezone');
        $department = isset($data['department']) ? Department::where('uuid', $data['department'])->first() : null;

        if (! JobOpening::seesAllDepartments($user)) {
            $department = $user->department;
        }

        return new self(
            user: $user,
            from: isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz)->startOfDay() : null,
            to: isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz)->endOfDay() : null,
            department: $department,
            unit: isset($data['unit']) ? Unit::where('uuid', $data['unit'])->first() : null,
            area: isset($data['area']) ? ProfessionalArea::from($data['area']) : null,
        );
    }

    /** O usuário está restrito a um departamento (gestor) e não pode trocar o filtro. */
    public function departmentLocked(): bool
    {
        return ! JobOpening::seesAllDepartments($this->user);
    }

    /**
     * Opções dos filtros para a tela (departamentos limitados ao que o usuário pode ver).
     *
     * @return array<string, mixed>
     */
    public static function options(User $user): array
    {
        $all = JobOpening::seesAllDepartments($user);

        return [
            'department_locked' => ! $all,
            'departments' => ($all ? Department::query() : Department::whereKey($user->department_id ?? 0))->orderBy('name')->get(['uuid', 'name'])->map(fn ($d) => ['id' => $d->uuid, 'name' => $d->name])->all(),
            'units' => Unit::query()->orderBy('name')->get(['uuid', 'name'])->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name])->all(),
            'areas' => ProfessionalArea::options(),
        ];
    }

    /** @return list<array{label: string, value: string}> */
    public function describe(): array
    {
        $period = match (true) {
            $this->from && $this->to => $this->from->format('d/m/Y').' a '.$this->to->format('d/m/Y'),
            (bool) $this->from => 'a partir de '.$this->from->format('d/m/Y'),
            (bool) $this->to => 'até '.$this->to->format('d/m/Y'),
            default => 'todo o período',
        };

        return array_values(array_filter([
            ['label' => 'Período', 'value' => $period],
            $this->department ? ['label' => 'Departamento', 'value' => $this->department->name] : null,
            $this->unit ? ['label' => 'Unidade', 'value' => $this->unit->name] : null,
            $this->area ? ['label' => 'Área', 'value' => $this->area->label()] : null,
        ]));
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'department' => $this->department?->uuid,
            'unit' => $this->unit?->uuid,
            'area' => $this->area?->value,
        ];
    }
}
