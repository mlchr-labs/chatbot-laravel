<?php

namespace App\Actions\Knowledge;

use App\Jobs\Knowledge\ChunkKnowledgeText;
use App\Jobs\Knowledge\EmbedKnowledgeChunks;
use App\Jobs\Knowledge\ExtractKnowledgeText;
use App\Jobs\Knowledge\PurgeKnowledgeIndexJob;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DispatchKnowledgeIngestion
{
    /** @param  bool  $reprocess  Clears chunks and vectors from a previous run before starting. */
    public function __invoke(KnowledgeSource $source, bool $reprocess = false): void
    {
        $sourceId = $source->id;

        $jobs = [
            new ExtractKnowledgeText($source),
            new ChunkKnowledgeText($source),
            new EmbedKnowledgeChunks($source),
        ];

        if ($reprocess) {
            array_unshift($jobs, new PurgeKnowledgeIndexJob($source));
        }

        Bus::chain($jobs)
            ->onQueue((string) config('knowledge.queue'))
            ->catch(static function (Throwable $e) use ($sourceId): void {
                app(FailKnowledgeSource::class)($sourceId, $e);
            })
            ->dispatch();
    }
}
