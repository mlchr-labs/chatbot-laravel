<?php

namespace App\Jobs\Knowledge;

use App\Contracts\VectorStore;
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

    public function handle(VectorStore $store): void
    {
        $store->deleteBySource($this->source->chatbot->ulid, $this->source->ulid);

        // Row goes last so a failed attempt can be retried with the source still known.
        $this->disk()->delete([$this->source->disk_path, $this->source->extractedTextPath()]);

        $this->source->chunks()->delete();
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
            'error' => 'Não foi possível excluir o documento. Tente novamente.',
        ]);
    }
}
