<?php

namespace App\Models;

use App\Domain\Recruitment\Enums\ApplicationStage;
use App\Domain\Recruitment\Enums\RejectionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro imutável de movimentação entre etapas. */
class ApplicationStageHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'application_stage_history';

    protected $fillable = ['from_stage', 'to_stage', 'reason', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['from_stage' => ApplicationStage::class, 'to_stage' => ApplicationStage::class, 'reason' => RejectionReason::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
