<?php

namespace App\Jobs\Knowledge;

use App\Actions\Knowledge\PurgeKnowledgeIndex;

/** First step of a reprocess: clears chunks and vectors so the chain never duplicates them. */
class PurgeKnowledgeIndexJob extends KnowledgeSourceJob
{
    public int $tries = 5;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(PurgeKnowledgeIndex $purge): void
    {
        if ($this->isBeingDeleted()) {
            return;
        }

        $purge($this->source);
    }
}
