<?php

namespace App\Jobs\Knowledge;

use App\Enums\KnowledgeSourceStatus;
use App\Models\KnowledgeSource;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

abstract class KnowledgeSourceJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A source deleted while a job is queued makes the job disappear instead of failing. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public KnowledgeSource $source)
    {
        $this->onQueue((string) config('knowledge.queue'));
    }

    /** The owner asked to delete the source while it was still being processed. */
    protected function isBeingDeleted(): bool
    {
        return $this->source->fresh()?->status === KnowledgeSourceStatus::Deleting;
    }

    protected function disk(): Filesystem
    {
        return Storage::disk((string) config('knowledge.disk'));
    }
}
