<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRequirement extends Model
{
    public $timestamps = false;

    protected $fillable = ['description', 'is_mandatory', 'sort'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'sort' => 'integer'];
    }

    /** @return BelongsTo<JobOpening, $this> */
    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }
}
