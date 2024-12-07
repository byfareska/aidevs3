<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:5', description: 'Task 5: 2024-11-08')]
final class Task5Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = <<<PROMPT
You are assistant that helps me to secure sensitive personal data.
<rules>
Replace sensitive data (defined in sensitive-data) to word CENZURA.
Return only the text nothing else.
You can't modify input data, but putting CENZURA. You must change only sensitive data, don't miss any punctation mark, don't add any characters and marks. Keep words in form you received. Don't replace "lata" with "lat".
If sensitive data is next to each other, replace it with one word CENZURA.
</rules>
<sensitive-data>
first name
middle name
last name
city
address
number
street and number
years old
</sensitive-data>
<example-input>
Osoba podejrzana to Jan Kowalski. Adres: Bydgoszcz, ul. Zamoyskiego 3. Wiek: 29 lat.
</example-input>
<example-output>
Osoba podejrzana to CENZURA. Adres: CENZURA, ul. CENZURA. Wiek: CENZURA lat.
</example-output>
<example-input>
Dane osoby podejrzanej: Łukasz Duszałski. Zamieszkały w Gorlicach na ulicy Boba 37. Ma 24 lata.
</example-input>
<example-output>
Dane osoby podejrzanej: CENZURA. Zamieszkały w CENZURA na ulicy CENZURA. Ma CENZURA lata.
</example-output>
PROMPT;

    public function __construct(
        private readonly AIChatRequestHandlerInterface $chatRequestHandler,
        private readonly ResultSender $resultSender,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,
        #[Autowire(env: 'TASK5_ENDPOINT')]
        private readonly string $endpoint,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $endpointData = str_replace('%api-key%', $this->resultSender->getApiKey(), $this->endpoint);
        $text = $this->httpClient->request('GET', $endpointData)->getContent();
        $output->writeln($text);

        $censored = $this->chatRequestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $text),
            )
            ->addCriteria(PrivacyCriteria::HIGH)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addOptions([
                'temperature' => 0.2
            ])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $output->writeln($censored);

        dump($this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'CENZURA', $censored));

        return Command::SUCCESS;
    }
}
