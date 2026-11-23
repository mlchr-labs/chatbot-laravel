<?php

namespace App\Enums;

enum KnowledgeSourceStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Embedding = 'embedding';
    case Indexed = 'indexed';
    case Failed = 'failed';
    // Hidden from the panel while the delete job removes vectors, file and rows.
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Extracting => 'Extraindo texto',
            self::Embedding => 'Gerando embeddings',
            self::Indexed => 'Indexado',
            self::Failed => 'Falhou',
            self::Deleting => 'Excluindo',
        };
    }

    /** Statuses that still change on their own, so the panel keeps polling. */
    public function isProcessing(): bool
    {
        return in_array($this, [self::Pending, self::Extracting, self::Embedding], true);
    }
}
