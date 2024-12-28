<?php declare(strict_types=1);

namespace App\Modelflow;

use ModelflowAi\Embeddings\Model\EmbeddingInterface;

interface SummarizedEmbedding extends EmbeddingInterface
{
    public function getSummarizedContent(): ?string;
    public function setSummarizedContent(string $summarizedContent): void;
}