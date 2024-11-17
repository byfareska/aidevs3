<?php declare(strict_types=1);

namespace App\Modelflow;

use ModelflowAi\Chat\Request\Message\MessagePart;
use ModelflowAi\Chat\Request\Message\MessagePartTypeEnum;

/**
 * Modelflow currently doesn't support images well
 */
final readonly class OpenAiImageMessagePart extends MessagePart
{
    public function __construct(
        public string $imageUrl,
    )
    {
        parent::__construct(MessagePartTypeEnum::BASE64_IMAGE);
    }

    public function enhanceMessage(array $message): array
    {
        if (!is_array($message['content'])) {
            $message['content'] = [];
        }

        $message['content'][] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $this->imageUrl,
            ],
        ];

        return $message;
    }
}
