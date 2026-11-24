<?php

return [

    // Private disk holding uploads and intermediate extracted text.
    'disk' => 'local',

    'queue' => env('KNOWLEDGE_QUEUE', 'knowledge'),

    'max_upload_kb' => 16 * 1024,

    'chunk_size' => (int) env('KNOWLEDGE_CHUNK_SIZE', 800),
    'chunk_overlap' => (int) env('KNOWLEDGE_CHUNK_OVERLAP', 120),

    // Passages per TEI request and per Qdrant upsert.
    'embed_batch_size' => 32,

    // Upload requests per minute per user (store endpoint).
    'uploads_per_minute_user' => (int) env('KNOWLEDGE_UPLOADS_PER_MINUTE_USER', 30),

    // Retry requests per minute per user (reprocess a failed source).
    'retries_per_minute_user' => (int) env('KNOWLEDGE_RETRIES_PER_MINUTE_USER', 10),

    // A source whose extracted text is longer than this many characters fails (guards embedding cost).
    'max_extracted_chars' => (int) env('KNOWLEDGE_MAX_EXTRACTED_CHARS', 2_000_000),

    // A source that would produce more chunks than this fails.
    'max_chunks_per_source' => (int) env('KNOWLEDGE_MAX_CHUNKS_PER_SOURCE', 5000),

    // Only the first N pages of a PDF are read (pdftotext -l). Extra pages are ignored.
    'pdf_max_pages' => (int) env('KNOWLEDGE_PDF_MAX_PAGES', 500),

    // Path to the poppler pdftotext binary. Empty lets spatie/pdf-to-text look in common locations.
    'pdftotext_path' => env('PDFTOTEXT_PATH'),

];
