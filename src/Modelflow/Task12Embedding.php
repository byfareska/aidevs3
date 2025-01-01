<?php declare(strict_types=1);

namespace App\Modelflow;

use DateTimeImmutable;
use ModelflowAi\Embeddings\Model\EmbeddingInterface;
use ModelflowAi\Embeddings\Model\EmbeddingTrait;

final class Task12Embedding implements EmbeddingInterface
{
    use EmbeddingTrait {
        toArray as protected traitToArray;
        fromArray as protected traitFromArray;
    }

    public function __construct(
        string $content,
        private DateTimeImmutable $noteDate
    )
    {
        $this->content = $content;
        $this->hash = sha1($content);
        $this->chunkNumber = 0;
    }

    public function getNoteDate(): DateTimeImmutable
    {
        return $this->noteDate;
    }

    /**
     * @return string[]
     */
    public function getIdentifierParts(): array
    {
        return [$this->chunkNumber, $this->hash];
    }

    public function toArray(): array
    {
        $arr = $this->traitToArray();

        foreach ($arr as &$value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
        }

        return $arr;
    }

    public static function fromArray(array $data): self
    {
        $data['noteDate'] = new DateTimeImmutable($data['noteDate']);

        return self::traitFromArray($data);
    }
}