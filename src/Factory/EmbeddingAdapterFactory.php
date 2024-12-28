<?php declare(strict_types=1);

namespace App\Factory;

use ModelflowAi\Embeddings\Adapter\Cache\CacheEmbeddingAdapter;
use ModelflowAi\Embeddings\Adapter\EmbeddingAdapterInterface;
use ModelflowAi\OpenaiAdapter\Embeddings\OpenaiEmbeddingAdapter;
use OpenAI\Contracts\ClientContract;
use Psr\Cache\CacheItemPoolInterface;

final readonly class EmbeddingAdapterFactory
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClientContract $openAi
    )
    {
    }

    public function create(): EmbeddingAdapterInterface
    {
        return new CacheEmbeddingAdapter(
            new OpenaiEmbeddingAdapter($this->openAi, 'text-embedding-3-small'),
            $this->cache
        );
    }
}