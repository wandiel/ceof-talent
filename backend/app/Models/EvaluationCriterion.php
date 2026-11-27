<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Critério de avaliação usado nos pareceres de entrevista (ex.: Comunicação). */
class EvaluationCriterion extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'evaluation_criteria';

    protected $fillable = ['name', 'description', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /** @return array{id: string, name: string, description: string|null} */
    public function snapshot(): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'description' => $this->description];
    }
}
