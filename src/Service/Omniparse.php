<?php declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\UriFactoryInterface;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Omniparse
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MimeTypesInterface $mimeTypes,
        private UriFactoryInterface $uriFactory,

        #[Autowire(env: 'OMNIPARSE_URL')]
        private string $omniparseUrl,
    )
    {
    }

    public function toMd(SplFileInfo $file, ?string $mime = null): string
    {
        $mime ??= $this->mimeTypes->getMimeTypes($file->getExtension())[0];
        $type = explode('/', $mime, 2)[0] ?? null;

        $endpoint = $this->uriFactory->createUri($this->omniparseUrl)
            ->withPath(match ($type) {
                'image' => '/parse_image/image',
                'audio' => '/parse_media/audio',
                'video' => '/parse_media/video',
                default => '/parse_document',
            });

        $formData = new FormDataPart([
            'file' => new DataPart(new File($file->getPathname()), $file->getFilename())
        ]);

        $response = $this->httpClient->request('POST', $endpoint->__toString(), [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
            'timeout' => 60000
        ]);

        return json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->text ?? '';
    }

}