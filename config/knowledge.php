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

    // Path to the poppler pdftotext binary. Empty lets spatie/pdf-to-text look in common locations.
    'pdftotext_path' => env('PDFTOTEXT_PATH'),

];
