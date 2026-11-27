<?php

namespace App\Models;

use App\Domain\Candidates\Enums\DocumentType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Arquivo anexado a um candidato. Fica no disco privado do servidor ("local")
 * ou em um bucket compatível com S3 ("s3"), conforme FILESYSTEM_DISK.
 */
class CandidateDocument extends Model
{
    use HasPublicUuid;

    /** Nome do disco onde os documentos ficam (config filesystems.documents). */
    public static function diskName(): string
    {
        return (string) config('filesystems.documents', 'local');
    }

    public static function storage(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

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
