<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\Grudziadz;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/task19', name: self::NAME, methods: ['POST'])]
final readonly class Task19Controller
{
    public const string NAME = 'task19';

    public function __construct(
        private Grudziadz $grudziadz,
        private LoggerInterface $logger,
    )
    {
    }

    public function __invoke(
        Request $request
    ): Response
    {
        $payload = $request->getPayload();

        if ($payload->has('instruction')) {
            $instruction = $payload->getString('instruction');
            $description = $this->grudziadz->trip($instruction);
            $this->logger->info('Instruction processed', ['instruction' => $instruction, 'description' => $description]);

            return new JsonResponse([
                'description' => $description,
            ]);
        }

        return new Response(null, Response::HTTP_BAD_REQUEST);
    }
}