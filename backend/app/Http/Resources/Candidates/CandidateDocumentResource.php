<?php

namespace App\Http\Resources\Candidates;

use App\Models\CandidateDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CandidateDocument */
class CandidateDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            // Leitura automática (Fase 7.9): pending, done, empty, unsupported, failed.
            'extraction_status' => $this->extraction_status,
            'extraction_method' => $this->extraction_method,
        ];
    }
}
