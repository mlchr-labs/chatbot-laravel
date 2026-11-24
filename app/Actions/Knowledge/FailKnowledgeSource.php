<?php

namespace App\Actions\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Models\KnowledgeSource;
use App\Support\KnowledgeFailureClassifier;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailKnowledgeSource
{
    public function __construct(private readonly KnowledgeFailureClassifier $classifier) {}

    public function __invoke(int $sourceId, Throwable $e): void
    {
        // Full details stay in the logs, the panel only gets the reason code.
        Log::error('Knowledge ingestion failed', ['source_id' => $sourceId, 'exception' => $e]);

        KnowledgeSource::query()
            ->whereKey($sourceId)
            ->where('status', '!=', KnowledgeSourceStatus::Deleting->value)
            ->update([
                'status' => KnowledgeSourceStatus::Failed->value,
                'failure_reason' => $this->classifier->classify($e)->value,
                'error' => null,
            ]);
    }
}
