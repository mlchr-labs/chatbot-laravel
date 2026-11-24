<?php

namespace App\Jobs\Knowledge;

use App\Actions\Knowledge\PurgeKnowledgeIndex;
use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeSourceStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteKnowledgeSource extends KnowledgeSourceJob
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(PurgeKnowledgeIndex $purge): void
    {
        $purge($this->source);

        // Row goes last so a failed attempt can be retried with the source still known.
        $this->disk()->delete($this->source->disk_path);

        $this->source->delete();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Knowledge source deletion failed', [
            'source_id' => $this->source->id,
            'exception' => $exception,
        ]);

        // Bring it back to the panel so the owner can try again.
        $this->source->update([
            'status' => KnowledgeSourceStatus::Failed,
            'failure_reason' => KnowledgeFailureReason::DeletionFailed,
            'error' => null,
        ]);
    }
}
