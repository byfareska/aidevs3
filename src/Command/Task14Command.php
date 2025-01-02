<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Transliterator;

#[AsCommand(name: 'task:14', description: 'Task 14: 2024-11-21')]
final class Task14Command extends TaskSolution
{
    private const string ANSWER_SYSTEM_PROMPT = <<<PROMPT
Wypisz wszystkie imiona, miasta oraz regiony z wiadomości, którą otrzymasz.
Wypisując odpowiedzi wypisz je w mianowniku.
Odpowiedź wygeneruj w formacie JSON z tablicami z kluczami names oraz places.
<example-response>{"names": ["Aleksander", "Krzysztof"], "places": ["Bydgoszcz", "Mazowsze"]}</example-response>
<example-response>{"names": ["Ewa", "Katarzyna"], "places": ["Kraków", "Śląsk"]}</example-response>
PROMPT;

    public function __construct(
        #[Autowire(env: 'TASK14_INITIAL_ENDPOINT')]
        private readonly string $initialEndpoint,

        #[Autowire(env: 'TASK14_PEOPLE_ENDPOINT')]
        private readonly string $peopleEndpoint,

        #[Autowire(env: 'TASK14_PLACES_ENDPOINT')]
        private readonly string $placesEndpoint,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        private readonly ResultSender $resultSender,
        private readonly HttpClientInterface $httpClient,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $initialData = $this->fetchInitialData();

        /**
         * @var array<string, bool> $possiblePlaces Place name to visited flag
         * @var array<string, bool> $possibleNames Name to visited flag
         */
        $possiblePlaces = [];
        $possibleNames = [];

        $placesAndNames = $this->askForPlacesAndNames($initialData);
        $isPlace = false;
        $name = null;

        do {
            if($isPlace && $name && in_array('BARBARA', $placesAndNames->names, true)){
                $output->write("Barbara may be in {$name}... ");
                $oracle = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'loop', $name);

                if($oracle->code === 0){
                    $output->writeln('Correct!');
                    dump($oracle);

                    return Command::SUCCESS;
                }

                $output->writeln('Wrong.');
            }

            $this->acknowledge($possiblePlaces, $possibleNames, $placesAndNames);
            [$name, $isPlace] = $this->pickFromList($possiblePlaces, $possibleNames);

            if($name === null){
                $output->writeln('<error>Could not find any more names or places</error>');
                return Command::FAILURE;
            }

            $data = $this->doRequest($name, $isPlace);
            $placesAndNames = $this->askForPlacesAndNames($data->message);
        } while (true);
    }

    private function fetchInitialData(): string
    {
        return $this->httpClient->request('GET', $this->initialEndpoint)->getContent();
    }

    /**
     * @param bool $isPlacesRequest - if true, places endpoint will be used, otherwise people endpoint
     */
    private function doRequest(string $query, bool $isPlacesRequest): stdClass
    {
        $endpoint = $isPlacesRequest ? $this->placesEndpoint : $this->peopleEndpoint;
        $response = $this->httpClient->request('POST', $endpoint ."?q={$query}", [
            'json' => [
                'query' => $query,
                'apikey' => $this->resultSender->getApiKey(),
            ],
        ]);

        return json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    }

    private function askForPlacesAndNames(string $message): stdClass
    {
        $response = json_decode(
            $this->requestHandler
                ->createRequest(
                    new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::ANSWER_SYSTEM_PROMPT),
                    new AIChatMessage(AIChatMessageRoleEnum::USER, $message)
                )
                ->addCriteria(CapabilityCriteria::INTERMEDIATE)
                ->addCriteria(FeatureCriteria::TEXT_GENERATION)
                ->addOptions([
                    'format' => 'json',
                ])
                ->build()
                ->execute()
                ->getMessage()
                ->content,
            false, 512, JSON_THROW_ON_ERROR
        );

        $transliterator = Transliterator::create("Any-Latin; Latin-ASCII");
        $toUpperAscii = static fn(string $name) => strtoupper($transliterator->transliterate($name));

        return (object)[
            'names' => array_map(static fn(string $name) => $toUpperAscii(explode(' ', $name, 2)[0]), $response->names),
            'places' => array_map($toUpperAscii, $response->places),
        ];
    }

    private function acknowledge(array &$possiblePlaces, array &$possibleNames, stdClass $placesAndNames): void
    {
        foreach ($placesAndNames->places as $place) {
            if (!isset($possiblePlaces[$place])) {
                $possiblePlaces[$place] = false;
            }
        }

        foreach ($placesAndNames->names as $name) {
            if (!isset($possibleNames[$name])) {
                $possibleNames[$name] = false;
            }
        }
    }

    private function pickFromList(array &$possiblePlaces, array &$possibleNames): array
    {
        foreach($possiblePlaces as $place => &$visited) {
            if (!$visited) {
                $visited = true;
                return [$place, true];
            }
        }

        foreach($possibleNames as $name => &$visited) {
            if (!$visited) {
                $visited = true;
                return [$name, false];
            }
        }

        return [null, null];
    }
}