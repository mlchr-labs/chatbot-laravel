<?php

namespace App\Jobs\Knowledge;

use App\Enums\KnowledgeFailureReason;
use App\Enums\KnowledgeFileType;
use App\Enums\KnowledgeSourceStatus;
use App\Exceptions\KnowledgeProcessingException;
use App\Support\TextExtractor;

class ExtractKnowledgeText extends KnowledgeSourceJob
{
    public int $tries = 1;

    public int $timeout = 120;

    public function handle(TextExtractor $extractor): void
    {
        if ($this->isBeingDeleted()) {
            return;
        }

        $this->source->update(['status' => KnowledgeSourceStatus::Extracting, 'error' => null]);

        $type = KnowledgeFileType::fromPath($this->source->disk_path)
            ?? throw new KnowledgeProcessingException('Unsupported file type.', KnowledgeFailureReason::UnsupportedFile);

        $text = $extractor->extract($this->disk()->path($this->source->disk_path), $type);

        if ($text === '') {
            throw new KnowledgeProcessingException('No text found in the document.', KnowledgeFailureReason::EmptyDocument);
        }

        if (mb_strlen($text) > (int) config('knowledge.max_extracted_chars')) {
            throw new KnowledgeProcessingException('Extracted text is over the configured cap.', KnowledgeFailureReason::DocumentTooLarge);
        }

        // Hand-off to the next job goes through the private disk, not the queue payload.
        $this->disk()->put($this->source->extractedTextPath(), $text);
    }
}
