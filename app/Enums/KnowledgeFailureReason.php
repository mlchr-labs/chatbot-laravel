<?php

namespace App\Enums;

/**
 * Why a knowledge source ended up failed. Stored as a code and rendered through message(),
 * so raw exception text (hosts, paths) never reaches the panel.
 */
enum KnowledgeFailureReason: string
{
    case EmbeddingUnavailable = 'embedding_unavailable';
    case VectorStoreUnavailable = 'vector_store_unavailable';
    case ExtractionFailed = 'extraction_failed';
    case EmptyDocument = 'empty_document';
    case UnsupportedFile = 'unsupported_file';
    case DocumentTooLarge = 'document_too_large';
    case DeletionFailed = 'deletion_failed';
    case Unknown = 'unknown';

    public function message(): string
    {
        return match ($this) {
            self::EmbeddingUnavailable => 'O serviço de embeddings estava indisponível. Tente novamente em alguns minutos.',
            self::VectorStoreUnavailable => 'O banco de vetores estava indisponível. Tente novamente em alguns minutos.',
            self::ExtractionFailed => 'Não foi possível ler o conteúdo do arquivo. Tente novamente ou envie outra versão dele.',
            self::EmptyDocument => 'Nenhum texto foi encontrado no documento. PDFs escaneados (imagens) não são suportados.',
            self::UnsupportedFile => 'Tipo de arquivo não suportado. Envie um PDF, TXT, MD ou HTML.',
            self::DocumentTooLarge => 'O documento é grande demais para ser indexado. Divida em arquivos menores e envie novamente.',
            self::DeletionFailed => 'Não foi possível excluir o documento. Tente excluir novamente.',
            self::Unknown => 'Ocorreu um erro inesperado ao processar o documento. Tente novamente e, se persistir, envie o arquivo outra vez.',
        };
    }

    /** False when reprocessing the same file cannot change the outcome. */
    public function retryable(): bool
    {
        return ! in_array($this, [self::EmptyDocument, self::UnsupportedFile, self::DocumentTooLarge, self::DeletionFailed], true);
    }
}
