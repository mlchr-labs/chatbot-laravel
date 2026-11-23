<?php

namespace App\Actions\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Exceptions\KnowledgeProcessingException;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailKnowledgeSource
{
    public const GENERIC_MESSAGE = 'Falha ao processar o documento. Tente enviar novamente.';

    public function __invoke(int $sourceId, Throwable $e): void
    {
        // Full details stay in the logs, the panel only gets a safe message.
        Log::error('Knowledge ingestion failed', ['source_id' => $sourceId, 'exception' => $e]);

        KnowledgeSource::query()
            ->whereKey($sourceId)
            ->where('status', '!=', KnowledgeSourceStatus::Deleting->value)
            ->update([
                'status' => KnowledgeSourceStatus::Failed->value,
                'error' => $e instanceof KnowledgeProcessingException ? $e->getMessage() : self::GENERIC_MESSAGE,
            ]);
    }
}
