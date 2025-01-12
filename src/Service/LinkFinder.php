<?php declare(strict_types=1);

namespace App\Service;

final readonly class LinkFinder
{
    public function findLinks(string $text): array
    {
        preg_match_all('/\bhttps?:\/\/[^\s<>"]+|www\.[^\s<>"]+/i', $text, $matches);

        return array_map(
            static fn(string $text) => trim($text, " \n\r\t\v\0."),
            $matches[0],
        );
    }

    public function findFileNames(string $text, ?array $allowedExtensions = null): array
    {
        preg_match_all('/\b[A-Za-z0-9_\-]+\.([A-Za-z]{3,4})\b/', $text, $matches);

        if ($allowedExtensions === null) {
            return $matches[0];
        }

        return array_filter(
            $matches[0],
            static fn(string $fileName) => array_any(
                $allowedExtensions,
                static fn(string $ext) => str_ends_with(strtolower($fileName), '.' . strtolower($ext))
            ),
        );
    }
}