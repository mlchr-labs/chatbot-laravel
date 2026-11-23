<?php

namespace App\Http\Resources;

use App\Models\KnowledgeSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KnowledgeSource
 */
class KnowledgeSourceResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->original_name,
            'size' => $this->size,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'processing' => $this->status->isProcessing(),
            'error' => $this->error,
            'chunks_count' => $this->chunks_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
