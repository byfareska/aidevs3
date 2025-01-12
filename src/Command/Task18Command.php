<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Modelflow\ProviderCriteria;
use App\Service\AiResponseParser;
use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:18', description: 'Task 18: 2024-11-27')]
final class Task18Command extends TaskSolution
{
    private const string CHECK_SYSTEM_PROMPT = <<<PROMPT
You have to answer to the question received in <question> tag.
All the data is available on website, but the answer may be on another link.
Current link is defined in <url> tag.
Current link content is defined as markdown in <content> tag.
If content contains answer for the question, prepend response with <answer-in-content>yes</answer-in-content> or <answer-in-content>no</answer-in-content> otherwise.
Then, if answer for question is available in <content> tag answer to the question in <answer> tag. Answer should be as short as possible, always reply in Polish language.
PROMPT;
    private const string GOTO_SYSTEM_PROMPT = <<<PROMPT
You have to answer to the question received in <question> tag.
Current link is defined in <url> tag.
List all links that are available on the page in <content> tag and describe, what do you think may they contain.
Then reply with <goto> tag with link that you think contains an answer, the link must be within content of current link.
Pick top3 goto links.
Link must be exactly the same as in <content> tag.
PROMPT;

    private const string ANSWER_SYSTEM_PROMPT = <<<PROMPT
You have to answer to the question received in <question> tag.
Current link is defined in <url> tag.
Create an answer to the question. The answer must be based on the content of the page.
Your answer should be as short as possible, always reply in Polish language.
PROMPT;

    public const string USER_PROMPT = <<<PROMPT
<question>{question}</question>
<url>{url}</url>
<content>
{content}
</content>
PROMPT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ResultSender $resultSender,
        private readonly HtmlConverter $html2md,
        private readonly AiResponseParser $responseParser,
        private readonly AIChatRequestHandlerInterface $requestHandler,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        #[Autowire(env: 'TASK18_ENDPOINT')]
        private readonly string $endpoint,

        #[Autowire(env: 'TASK18_WEBSITE_URL')]
        private readonly string $websiteUrl,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $endpointLink = str_replace('%api-key%', $this->resultSender->getApiKey(), $this->endpoint);
        $questions = json_decode($this->httpClient->request('GET', $endpointLink)->getContent(), false, 512, JSON_THROW_ON_ERROR);

        $answers = [];
        foreach ($questions as $id => $question) {
            $output->writeln("<info>{$question}</info>");
            $answer = $this->findAnswer($output, $question);
            $output->writeln("<info>{$answer}</info>");

            $answers[$id] = $answer;
        }

        $result = $this->resultSender->send($this->endpointVerify, 'softo', $answers);
        dump($result);

        return Command::SUCCESS;
    }

    private function findAnswer(OutputInterface $output, string $question, ?array $nextLinks = null): string
    {
        $visitedLinks = [];

        $latestUrl = $this->websiteUrl;
        $isRetry = $nextLinks !== null;
        $nextLinks = $nextLinks ?? [$latestUrl];

        while (!empty($nextLinks)) {
            $currentUrl = UriResolver::resolve(array_shift($nextLinks), $latestUrl);
            $output->writeln("Navigating to: {$currentUrl}");

            if (in_array($currentUrl, $visitedLinks, true)) {
                continue;
            }

            $visitedLinks[] = $currentUrl;

            $responseBody = $this->httpClient->request('GET', $currentUrl)->getContent();
            if (str_contains(strtoupper($responseBody), 'ANTY BOT PAGE')) {
                continue;
            }
            $responseBody = $this->removeHiddenElements($responseBody);

            $siteContent = $this->html2md->setOptions([
                'strip_tags' => true,
            ])->convert($responseBody);

            $responseCheck = $this->ask(self::CHECK_SYSTEM_PROMPT, $currentUrl, $question, $siteContent);
            $answerInContext = ($this->responseParser->fetchOneByTag($responseCheck, 'answer-in-content') ?? '') === 'yes';

            if ($answerInContext) {
                return $this->ask(self::ANSWER_SYSTEM_PROMPT, $currentUrl, $question, $siteContent);
            }

            $responseGoto = $this->ask(self::GOTO_SYSTEM_PROMPT, $currentUrl, $question, $siteContent);

            if (!empty($gotos = $this->responseParser->fetchByTag($responseGoto, 'goto'))) {
                $gotos = array_map(static fn(string $l) => UriResolver::resolve($l, $currentUrl), $gotos);
                $unvisitedLinks = array_diff($gotos, $visitedLinks);
                array_push($nextLinks, ...$unvisitedLinks);
                $latestUrl = $currentUrl;
            }
        }

        if ($isRetry) {
            throw new RuntimeException("No answer found for question: {$question}");
        }

        $navLinks = $this->getNavLinks($responseBody, $currentUrl);
        $unvisitedLinks = array_diff($navLinks, $visitedLinks);

        return $this->findAnswer($output, $question, $unvisitedLinks);
    }

    private function ask(string $prompt, string $url, string $question, string $pageContent): string
    {
        return $this->requestHandler
            ->createRequest(
                ...ChatPromptTemplate::create(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, $prompt),
                new AIChatMessage(AIChatMessageRoleEnum::USER, self::USER_PROMPT)
            )->format([
                'question' => $question,
                'url' => $url,
                'content' => $pageContent,
            ])
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addCriteria(ProviderCriteria::GOOGLE)
            ->addOptions(['temperature' => 0.5])
            ->build()
            ->execute()
            ->getMessage()
            ->content;
    }

    private function removeHiddenElements($html): string
    {
        // Load the HTML into a DOMDocument object
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress warnings for malformed HTML
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Get all elements
        $xpath = new DOMXPath($dom);
        $nodesToRemove = $xpath->query('//*[@class[contains(., "hidden")]]');

        // Remove nodes containing 'hidden' class
        foreach ($nodesToRemove as $node) {
            $node->parentNode->removeChild($node);
        }

        // Return modified HTML
        return $dom->saveHTML();
    }

    private function getNavLinks(string $html, string $currentUrl): array
    {
        // Load the HTML into a DOMDocument object
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress warnings for malformed HTML
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Use XPath to select all <a> tags within <nav>
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//nav//a'); // Select all <a> tags under <nav>

        $result = [];

        foreach ($links as $link) {
            $result[] = $link->getAttribute('href');
        }

        return array_map(static fn(string $l) => UriResolver::resolve($l, $currentUrl), $result);
    }
}