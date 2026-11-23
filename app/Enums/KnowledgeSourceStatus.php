<?php

namespace App\Enums;

enum KnowledgeSourceStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Embedding = 'embedding';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Extracting => 'Extraindo texto',
            self::Embedding => 'Gerando embeddings',
            self::Indexed => 'Indexado',
            self::Failed => 'Falhou',
        };
    }
}
