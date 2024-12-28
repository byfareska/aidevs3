<?php declare(strict_types=1);

namespace App\Modelflow;

use ModelflowAi\Embeddings\Model\EmbeddingTrait;

final class Task10Embedding implements SummarizedEmbedding
{
    use EmbeddingTrait;

    private ?string $summarizedContent = null;

    public function __construct(string $content)
    {
        $this->content = $content;
        $this->hash = sha1($content);
        $this->chunkNumber = 0;
    }

    /**
     * @return string[]
     */
    public function getIdentifierParts(): array
    {
        return [$this->chunkNumber, $this->hash];
    }

    public function getSummarizedContent(): ?string
    {
        return $this->summarizedContent;
    }

    public function setSummarizedContent(string $summarizedContent): void
    {
        $this->summarizedContent = $summarizedContent;
    }
}