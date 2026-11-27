<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Etiqueta compartilhada do banco de talentos (ex.: "Banco de reserva", "Perfil liderança"). */
class Tag extends Model
{
    use HasPublicUuid;

    public const COLORS = ['slate', 'blue', 'green', 'amber', 'red', 'purple', 'pink', 'teal'];

    protected $fillable = ['name', 'color', 'created_by'];

    /** @return BelongsToMany<Candidate, $this> */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class)->withPivot(['tagged_by', 'created_at']);
    }
}
