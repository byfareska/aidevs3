<?php declare(strict_types=1);

namespace App\Factory;

use App\Rag\SummarizedEmbeddingGenerator;
use App\Rag\Summarizer;
use ModelflowAi\Embeddings\Adapter\EmbeddingAdapterInterface;
use ModelflowAi\Embeddings\Formatter\EmbeddingFormatter;
use ModelflowAi\Embeddings\Generator\EmbeddingGeneratorInterface;
use ModelflowAi\Embeddings\Splitter\EmbeddingSplitter;

final readonly class EmbeddingGeneratorFactory
{
    public function __construct(
        private EmbeddingAdapterInterface $embeddingAdapter,
        private Summarizer $summarizer,
    )
    {
    }

    public function create(): EmbeddingGeneratorInterface
    {
        $embeddingSplitter = new EmbeddingSplitter(5000);
        $embeddingFormatter = new EmbeddingFormatter();

        return new SummarizedEmbeddingGenerator($embeddingSplitter, $embeddingFormatter, $this->embeddingAdapter, $this->summarizer);
    }
}