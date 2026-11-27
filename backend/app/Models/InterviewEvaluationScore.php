<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota de um critério dentro de um parecer. */
class InterviewEvaluationScore extends Model
{
    public $timestamps = false;

    protected $fillable = ['criterion_uuid', 'criterion_name', 'score', 'comment'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }
}
