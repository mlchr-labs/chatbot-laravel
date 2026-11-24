<?php

namespace App\Actions\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Exceptions\KnowledgeRetryException;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Storage;

class RetryKnowledgeIngestion
{
    public function __construct(private readonly DispatchKnowledgeIngestion $ingest) {}

    /** @throws KnowledgeRetryException */
    public function __invoke(KnowledgeSource $source): void
    {
        if ($source->status !== KnowledgeSourceStatus::Failed) {
            throw KnowledgeRetryException::notFailed();
        }

        if ($source->failure_reason !== null && ! $source->failure_reason->retryable()) {
            throw KnowledgeRetryException::notRetryable();
        }

        if (! Storage::disk((string) config('knowledge.disk'))->exists($source->disk_path)) {
            throw KnowledgeRetryException::fileMissing();
        }

        // Atomic claim: a double click (or two tabs) dispatches a single chain.
        $claimed = KnowledgeSource::query()
            ->whereKey($source->id)
            ->where('status', KnowledgeSourceStatus::Failed->value)
            ->update([
                'status' => KnowledgeSourceStatus::Pending->value,
                'failure_reason' => null,
                'error' => null,
            ]);

        if ($claimed === 0) {
            throw KnowledgeRetryException::notFailed();
        }

        $source->refresh();

        ($this->ingest)($source, reprocess: true);
    }
}
