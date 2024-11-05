<?php declare(strict_types=1);

namespace App\AiDevs\Xyz;

final readonly class XyzResponse
{
    public function __construct(
        public int $msgId,
        public string $text
    )
    {
    }
}