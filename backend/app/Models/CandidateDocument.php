<?php

namespace App\Models;

use App\Domain\Candidates\Enums\DocumentType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivo anexado a um candidato, guardado no disco privado. */
class CandidateDocument extends Model
{
    use HasPublicUuid;

    public const DISK = 'local';

    protected $fillable = ['type', 'original_name', 'path', 'mime_type', 'size', 'sha256', 'uploaded_by'];

    protected $hidden = ['path', 'extracted_text'];

    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'size' => 'integer', 'extracted_at' => 'datetime'];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
