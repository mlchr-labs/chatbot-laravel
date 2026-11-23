<?php

namespace App\Actions\Knowledge;

use App\Jobs\Knowledge\ChunkKnowledgeText;
use App\Jobs\Knowledge\EmbedKnowledgeChunks;
use App\Jobs\Knowledge\ExtractKnowledgeText;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DispatchKnowledgeIngestion
{
    public function __invoke(KnowledgeSource $source): void
    {
        $sourceId = $source->id;

        Bus::chain([
            new ExtractKnowledgeText($source),
            new ChunkKnowledgeText($source),
            new EmbedKnowledgeChunks($source),
        ])
            ->onQueue((string) config('knowledge.queue'))
            ->catch(static function (Throwable $e) use ($sourceId): void {
                app(FailKnowledgeSource::class)($sourceId, $e);
            })
            ->dispatch();
    }
}
