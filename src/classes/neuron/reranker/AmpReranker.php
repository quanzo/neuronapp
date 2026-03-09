<?php

namespace app\modules\neuron\classes\neuron\reranker;

use NeuronAI\Chat\Enums\MessageRole;
use function Amp\ParallelFunctions\parallelMap;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * Class AmpReranker
 * 
 * ! Это непроверенный пример реранкера
 *
 * Постпроцессор RAG, который переупорядочивает (rerank) массив документов,
 * запрашивая у LLM-провайдера оценку релевантности каждого документа
 * относительно вопроса пользователя. Оценка производится параллельно с помощью
 * библиотеки Amp, после чего возвращаются topN наиболее релевантных документов.
 */
class AmpReranker implements PostProcessorInterface
{
    /**
     * Провайдер LLM, используемый для оценки релевантности документов.
     *
     * @var AIProviderInterface
     */
    private AIProviderInterface $provider;

    /**
     * Количество документов, возвращаемых после ранжирования.
     *
     * @var int
     */
    private int $topN;

    /**
     * Конструктор ранкера.
     *
     * @param AIProviderInterface $provider Экземпляр провайдера (например, Ollama).
     * @param int $topN Количество документов для возврата после ранжирования.
     */
    public function __construct(
        AIProviderInterface $provider,
        int $topN = 5
    ) {
        $this->topN = $topN;
        $this->provider = $provider;
    }

    /**
     * Переупорядочивает массив документов по релевантности к вопросу пользователя.
     *
     * Каждый документ оценивается LLM-провайдером по шкале от 0 до 100.
     * Оценки вычисляются параллельно с помощью Amp, после чего документы
     * сортируются по убыванию и возвращаются topN лучших.
     *
     * @param Message $question   Вопрос пользователя, относительно которого оцениваются документы.
     * @param Document[] $documents Массив документов для ранжирования.
     *
     * @return Document[] Отранжированный и усечённый до topN массив документов.
     */
    public function process(Message $question, array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $questionText = $question->getContent();

        $items = [];
        foreach ($documents as $index => $document) {
            $items[] = [
                'index'  => $index,
                'prompt' => $this->buildPrompt($questionText, $document->getContent()),
            ];
        }

        $baseProvider = $this->provider;

        $results = parallelMap($items, function (array $item) use ($baseProvider): array {
            // Создаём новый экземпляр провайдера на основе конфигурации
            $provider = clone $baseProvider;

            try {
                $response = $provider->chat(new Message(MessageRole::USER, $item['prompt']));
                $score = self::parseScore($response->getContent());
            } catch (\Throwable $e) {
                error_log("Reranker error for doc {$item['index']}: " . $e->getMessage());
                $score = 0;
            }

            return [
                'index' => $item['index'],
                'score' => $score,
            ];
        });


        $scores = [];
        foreach ($results as $result) {
            $scores[$result['index']] = $result['score'];
        }

        arsort($scores);
        $topDocuments = [];
        $counter = 0;
        foreach (array_keys($scores) as $index) {
            if ($counter >= $this->topN) {
                break;
            }
            if (!isset($documents[$index])) {
                continue;
            }

            $topDocuments[] = $documents[$index];
            $counter++;
        }

        return $topDocuments;
    }

    /**
     * Формирует промпт для оценки релевантности документа к заданному вопросу.
     *
     * @param string $question  Текст вопроса пользователя.
     * @param string $document  Текст документа, который необходимо оценить.
     *
     * @return string Сформированный промпт для LLM.
     */
    protected function buildPrompt(string $question, string $document): string
    {
        return <<<PROMPT
        Оцени релевантность следующего документа для данного запроса. Ответь только одним числом от 0 до 100, где 0 — совсем нерелевантно, а 100 — идеально подходит.
        
        Запрос пользователя: {$question}
        
        Документ:
        {$document}
        
        Оценка (только число):
        PROMPT;
    }

    /**
     * Разбирает числовую оценку релевантности из ответа модели и нормализует её.
     *
     * Извлекается последнее целое число из текста ответа, после чего значение
     * приводится к диапазону от 0 до 100. При невозможности извлечь число
     * возвращается 0.
     *
     * @param string|null $scoreText Текст ответа модели.
     *
     * @return int Нормализованная оценка от 0 до 100.
     */
    private static function parseScore(?string $scoreText): int
    {
        if ($scoreText === null) {
            return 0;
        }

        $scoreText = trim($scoreText);

        if ($scoreText === '') {
            return 0;
        }

        if (!preg_match_all('/-?\d+/', $scoreText, $matches) || empty($matches[0])) {
            return 0;
        }

        $raw = (int) end($matches[0]);

        if ($raw < 0) {
            $raw = 0;
        }

        if ($raw > 100) {
            $raw = 100;
        }

        return $raw;
    }
}