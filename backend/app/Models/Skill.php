<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Competência (catálogo compartilhado, sem diferenciar maiúsculas). */
class Skill extends Model
{
    protected $fillable = ['name'];

    /** @return BelongsToMany<Candidate, $this> */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class)->withPivot('level');
    }
}
