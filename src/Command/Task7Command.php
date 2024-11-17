<?php declare(strict_types=1);

namespace App\Command;

use App\Modelflow\OpenAiImageMessagePart;
use App\Service\AiResponseParser;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'task:7', description: 'Task 7: 2024-11-12')]
final class Task7Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = <<<PROMPT
Zdjęcie które otrzymasz jest urywkiem mapy. Wypisz listę widocznych nazw ulic w tagu ulica np. <ulica>Fordońska</ulica> <ulica>Józefa Piłsudzkiego</ulica>.
Wypisz również listę miejsc, które są widoczne, niebędące ulicami w tagu miejsce np. <miejsce>Plac Teatralny</miejsce> <miejsce>Przystanek autobusowy przy ulicy Chełmińskiej</miejsce> <miejsce>Żabka</miejsce>.
Biorąc pod uwagę wszystkie widoczne nazwy ulic i miejsc przy ulicach, wypisz możliwe miejscowości, które są widoczne na zdjęciu.
{foundInPrevious}
Opisz swój tok myślenia.
Wszystkie miejscowości zapisz w tagu miasto np. <miasto>Jędrzejów</miasto> <miasto>Kraków</miasto>.
PROMPT;

    public function __construct(
        private readonly AIChatRequestHandlerInterface $requestHandler,
        private readonly ImageManagerInterface $imageManager,
        private readonly AiResponseParser $aiResponseParser,

        #[Autowire(env: 'TASK7_MAP_PATH')]
        private readonly string $mapPath,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $kernelDir,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logFile = "{$this->kernelDir}/var/ai-responses-task7-" . time() . ".txt";
        $output->writeln('Splitting the image into parts...');
        $parts = $this->imageToParts();

        $collectedCities = [];
        foreach ($parts as $i => $part) {
            $output->write("Part #{$i}: ");

            [$cities, $streets, $places] = $this->imageToDetails($part, $logFile, $collectedCities);
            array_push($collectedCities, ...$cities);

            $output->writeln(sprintf(
                '%s (ulice: %s; miejsca: %s)',
                empty($cities) ? '-' : implode(', ', $cities),
                empty($cities) ? '-' : implode(', ', $streets),
                empty($cities) ? '-' : implode(', ', $places),
            ));

            //In my case:
            //Part #0: Grudziądz (ulice: Kalińkowa, Brzeźna, Chełmińska, Chopina; miejsca: Przystanek autobusowy przy ulicy Chełmińskiej)
            //Part #1: Białystok (ulice: Kalinowska, Konstantego Ildefonsa Gałczyńskiego, Stroma, Władysława Reymonta; miejsca: Przystanek autobusowy)
            //Part #2: Kraków (ulice: Twardowskiego, Dworska, Boczna, Słomiana, Szwedzka; miejsca: Żabka, Luch Massage, Lewiatan)
            //Part #3: Grudziądz (ulice: Cmentarna, Parkowa, Pastwowa; miejsca: Cmentarz ewangelicko-augsburski)
            //
            //Part #1 is wrong, but it may be due typo in original map. It's not a big deal, because we have 3 other parts with correct cities. We are looking for Grudziądz.
        }

        $votes = array_count_values($collectedCities);
        arsort($votes);

        $output->writeln('Ranking: ');
        foreach ($votes as $city => $count) {
            $output->writeln("{$city}: {$count}");
        }

        $output->writeln("Done! All AI responses saved to {$logFile}");

        return Command::SUCCESS;
    }

    /**
     * @return ImageInterface[]
     */
    private function imageToParts(): array
    {
        $maps = fn() => $this->imageManager->read($this->mapPath);

        return [
            $maps()->crop(860 - 300, 735 - 100, 300, 100),
            $maps()->crop(1362 - 914, 750 - 125, 914, 125),
            $maps()->crop(1400 - 225, 1250 - 770, 225, 770),
            $maps()->crop(1305 - 340, 1800 - 1260, 340, 1260),
        ];
    }

    private function imageToDetails(ImageInterface $image, string $logFile, array $foundInPreviousCalls): array
    {
        $messages = ChatPromptTemplate::create(
            new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
            new AIChatMessage(AIChatMessageRoleEnum::USER, new OpenAiImageMessagePart($image->toJpeg()->toDataUri()))
        )->format([
            'foundInPrevious' => empty($foundInPreviousCalls) ? '' : 'Być może jest to ' . implode(', ', array_unique($foundInPreviousCalls)),
        ]);

        $response = $this->requestHandler->createRequest(...$messages)
            ->addCriteria(PrivacyCriteria::MEDIUM)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addOptions([
                'temperature' => 0.4
            ])
            ->build()
            ->execute();

        $content = $response->getMessage()->content;

        file_put_contents($logFile, "{$content}\n\n-----------------------\n\n" . PHP_EOL, FILE_APPEND);

        return [
            $this->aiResponseParser->fetchByTag($content, 'miasto'),
            $this->aiResponseParser->fetchByTag($content, 'ulica'),
            $this->aiResponseParser->fetchByTag($content, 'miejsce'),
        ];
    }
}