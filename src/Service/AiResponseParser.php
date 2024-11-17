<?php declare(strict_types=1);

namespace App\Service;

final readonly class AiResponseParser
{
    public function fetchOneByTag(string $response, string $tag): ?string
    {
        preg_match("/<{$tag}>(.+)<\/{$tag}>/U", $response, $regexOutput);
        return $regexOutput[1] ?? null;
    }

    public function fetchByTag(string $response, string $tag): array
    {
        preg_match_all("/<{$tag}>(.+)<\/{$tag}>/U", $response, $regexOutput);
        return $regexOutput[1] ?? [];
    }
}