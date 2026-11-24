<?php

namespace App\Http\Resources;

use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeSourceStatus;
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
        $failed = $this->status === KnowledgeSourceStatus::Failed;
        $reason = $failed ? ($this->failure_reason ?? KnowledgeFailureReason::Unknown) : null;

        return [
            'ulid' => $this->ulid,
            'name' => $this->original_name,
            'size' => $this->size,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'processing' => $this->status->isProcessing(),
            'failure_reason' => $reason?->value,
            // Fixed text per reason: raw exception messages never reach the panel.
            'error' => $reason?->message(),
            'can_retry' => $reason?->retryable() ?? false,
            'chunks_count' => $this->chunks_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
