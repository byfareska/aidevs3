<?php declare(strict_types=1);

namespace App\Rag;

use App\Modelflow\SummarizedEmbedding;
use ModelflowAi\Embeddings\Adapter\EmbeddingAdapterInterface;
use ModelflowAi\Embeddings\Formatter\EmbeddingFormatterInterface;
use ModelflowAi\Embeddings\Generator\EmbeddingGenerator;
use ModelflowAi\Embeddings\Model\EmbeddingInterface;
use ModelflowAi\Embeddings\Splitter\EmbeddingSplitterInterface;

final class SummarizedEmbeddingGenerator extends EmbeddingGenerator
{
    public function __construct(
        private readonly EmbeddingSplitterInterface $embeddingSplitter,
        private readonly EmbeddingFormatterInterface $embeddingFormatter,
        private readonly EmbeddingAdapterInterface $embeddingAdapter,
        private readonly Summarizer $summarizer,
    )
    {
        parent::__construct($embeddingSplitter, $embeddingFormatter, $embeddingAdapter);
    }

    public function generateEmbedding(EmbeddingInterface $embedding, ?callable $headerGenerator = null): array
    {
        $result = [];
        foreach ($this->embeddingSplitter->splitEmbedding($embedding) as $splitEmbedding) {
            $result[] = $newEmbedding = $this->embeddingFormatter->formatEmbedding(
                $splitEmbedding,
                $headerGenerator ? $headerGenerator($embedding) : '',
            );

            if ($newEmbedding instanceof SummarizedEmbedding && $newEmbedding->getSummarizedContent() === null) {
                $newEmbedding->setSummarizedContent($this->summarizer->summarize($newEmbedding->getContent()));
            }

            $newEmbedding->setVector($this->embeddingAdapter->embedText(
                $newEmbedding instanceof SummarizedEmbedding
                    ? $newEmbedding->getSummarizedContent()
                    : $newEmbedding->getContent()
            ));
        }

        return $result;
    }
}