<?php declare(strict_types=1);

namespace App\Modelflow;

use ModelflowAi\Embeddings\Store\EmbeddingsStoreInterface;

interface CrudEmbeddingsStoreInterface extends EmbeddingsStoreInterface
{
    public function exists(): bool;

    public function isEmpty(): bool;

    public function create(): void;

    public function remove(): void;
}