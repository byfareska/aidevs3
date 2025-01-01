<?php declare(strict_types=1);

namespace App\Service;

final readonly class AiResponseParser
{
    public function fetchOneByCodeBlock(string $response, string $lang): ?string
    {
        preg_match("/```{$lang}[\r\n|\r|\n](.+)```/Us", $response, $regexOutput);
        return $regexOutput[1] ?? null;
    }

    public function fetchOneByTag(string $response, string $tag): ?string
    {
        preg_match("/<{$tag}>(.+)<\/{$tag}>/Us", $response, $regexOutput);
        return $regexOutput[1] ?? null;
    }

    public function fetchByTag(string $response, string $tag): array
    {
        preg_match_all("/<{$tag}>(.+)<\/{$tag}>/Us", $response, $regexOutput);
        return $regexOutput[1] ?? [];
    }
}