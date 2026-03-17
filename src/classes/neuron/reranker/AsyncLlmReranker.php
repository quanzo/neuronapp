<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\reranker;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use app\modules\neuron\helpers\CallableWrapper;

use function Amp\ParallelFunctions\parallelMap;

/**
 * Class AsyncLlmReranker
 *
 * Постпроцессор RAG, выполняющий асинхронный (параллельный) реранк результатов
 * векторного поиска с использованием LLM-провайдера NeuronAI.
 *
 * Назначение класса:
 * - На вход получает вопрос пользователя ({@see Message}) и массив документов
 *   ({@see Document}), уже найденных векторным поиском.
 * - Для каждого документа формирует отдельный промпт, в котором просит модель
 *   оценить релевантность документа к вопросу по шкале от 0 до 100.
 * - С помощью Amp ParallelFunctions ({@see parallelMap}) параллельно вызывает
 *   LLM для каждого документа.
 * - Разбирает числовой скор из ответа модели, нормализует его в диапазон 0–100.
 * - Сортирует документы по убыванию score и возвращает topN наиболее
 *   релевантных документов.
 *
 * Провайдер LLM **не передаётся как объект**, а описывается конфигурацией в
 * виде массива, совместимого с {@see CallableWrapper::call()}.
 * Это важно для корректной работы Amp: массив-конфигурация полностью
 * сериализуем, поэтому может безопасно передаваться в воркеры, где уже
 * создаётся «живой» экземпляр провайдера.
 *
 * Пример конфигурации для Ollama-провайдера:
 *
 * ```php
 * $providerConfig = [
 *     CallableWrapper::class,
 *     'createObject',
 *     'class'      => Ollama::class,
 *     'url'        => 'http://localhost:11434/api',
 *     'parameters' => [
 *         'options' => [
 *             'temperature'    => 0.2,
 *             'top_p'          => 0.95,
 *             'repeat_penalty' => 1.1,
 *             'num_ctx'        => $contextWindow,
 *         ],
 *     ],
 *     'model'      => 'bbjson/bge-reranker-base',
 * ];
 * ```
 *
 * Такой подход позволяет:
 * - не сериализовать целиком объект конфигурации агента и связанные с ним
 *   сервисы;
 * - создавать экземпляры провайдера непосредственно в воркерах Amp;
 * - легко переключать модели или провайдеры, изменяя только массив-конфиг.
 *
 * Такой подход позволяет:
 * - Повысить качество результатов за счёт дополнительной семантической оценки
 *   содержимого документов поверх «сырого» векторного поиска.
 * - Не блокировать выполнение на последовательных запросах к LLM, а
 *   распараллелить вычисления и тем самым уменьшить общее время ответа.
 */
final class AsyncLlmReranker implements PostProcessorInterface
{
    /**
     * Конфигурация провайдера LLM для CallableWrapper.
     *
     * Вместо хранения «живого» объекта провайдера (который может содержать
     * несериализуемые ресурсы) здесь хранится массив с описанием того, как
     * создать провайдер через {@see CallableWrapper::call()}.
     *
     * Пример структуры массива см. в описании класса.
     *
     * @var array<mixed>
     */
    private array $providerConfig;

    /**
     * Количество документов, которое будет возвращено после ранжирования.
     *
     * В процессе работы реранкера все документы получают численную оценку
     * релевантности. Далее документы сортируются по убыванию этой оценки,
     * и из результата берутся первые topN элементов. Если документов меньше,
     * чем topN, будут возвращены все доступные документы.
     *
     * @var int
     */
    private int $topN;

    /**
     * Конструктор асинхронного реранкера.
     *
     * @param array<mixed> $providerConfig Конфигурация провайдера LLM.
     *                                    Массив должен быть совместим с
     *                                    {@see CallableWrapper::call()} и в
     *                                    итоге возвращать экземпляр провайдера,
     *                                    реализующий метод chat().
     * @param int          $topN          Максимальное количество документов,
     *                                    возвращаемых после ранжирования.
     */
    public function __construct(
        array $providerConfig,
        int $topN = 5
    ) {
        $this->providerConfig = $providerConfig;
        $this->topN           = $topN;
    }

    /**
     * Выполняет асинхронный реранк массива документов относительно вопроса пользователя.
     *
     * Алгоритм работы:
     * 1. Если массив документов пустой — немедленно возвращается пустой массив.
     * 2. Из вопроса пользователя извлекается текстовое содержимое.
     * 3. Для каждого документа формируется структура с индексом и готовым промптом,
     *    в который включаются текст вопроса и текст документа.
     * 4. С помощью {@see parallelMap()} массив структур обрабатывается в
     *    параллельных воркерах: для каждого элемента вызывается LLM-провайдер,
     *    из ответа извлекается числовая оценка и нормализуется.
     * 5. Собирается карта index => score, сортируется по убыванию score.
     * 6. Документам проставляется score через {@see Document::setScore()},
     *    выбираются первые topN документов и возвращаются вызывающему коду.
     *
     * @param Message    $question  Вопрос пользователя, относительно которого
     *                              оценивается релевантность документов.
     * @param Document[] $documents Массив документов, полученных из векторного
     *                              поиска и подлежащих дополнительному реранку.
     *
     * @return Document[] Массив документов, отсортированных по убыванию
     *                    релевантности и усечённых до размера topN.
     */
    public function process(Message $question, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $questionText   = $question->getContent();
        $providerConfig = $this->providerConfig;

        /**
         * Массив «заданий» для параллельной обработки.
         *
         * Каждый элемент содержит:
         * - index  — исходный индекс документа в массиве $documents;
         * - prompt — готовый текст промпта, который будет отправлен в LLM.
         *
         * Такой формат позволяет передать в воркер только сериализуемые данные,
         * не замыкая на себе объект конфигурации или сам документ.
         *
         * @var array<int,array{index:int|string,prompt:string}> $items
         */
        $items = [];

        foreach ($documents as $index => $document) {
            $items[] = [
                'index'  => $index,
                'prompt' => $this->buildPrompt($questionText, $document->getContent()),
            ];
        }

        /**
         * Параллельно вызываем LLM для каждого промпта.
         *
         * Используется статическое замыкание (static function), чтобы не
         * захватывать $this и упростить сериализацию колбэка для Amp.
         * Воркеры получают только массив-конфигурацию провайдера и сами
         * создают из него экземпляр через {@see CallableWrapper::call()}.
         *
         * На выходе получаем массив структур формата:
         * - index — индекс документа в исходном массиве;
         * - score — нормализованный целочисленный скор 0–100.
         *
         * @var array<int,array{index:int|string,score:int}> $results
         */
        $results = parallelMap(
            $items,
            /**
             * Обработчик одного документа в отдельном воркере.
             *
             * На основании массива-конфига лениво создаёт провайдера LLM и
             * запрашивает у него числовую оценку релевантности документа.
             *
             * @param array{index:int|string,prompt:string} $item
             *
             * @return array{index:int|string,score:int}
             */
            static function (array $item) use ($providerConfig): array {
                /**
                 * Провайдер кешируется в статической переменной внутри
                 * воркера: при первом обращении создаётся через
                 * CallableWrapper::call(), далее переиспользуется.
                 *
                 * Благодаря этому каждый воркер создаёт только один
                 * экземпляр провайдера, а не новый объект на каждый документ.
                 */
                static $provider = null;

                if ($provider === null) {
                    $provider = CallableWrapper::call($providerConfig);
                }

                try {
                    $response  = $provider->chat(
                        new Message(MessageRole::USER, $item['prompt'])
                    );
                    $scoreText = $response->getContent();
                    $score     = self::parseScore($scoreText);
                } catch (\Throwable $e) {
                    error_log(
                        sprintf(
                            'AsyncLlmReranker error for doc %s: %s',
                            (string) $item['index'],
                            $e->getMessage()
                        )
                    );
                    $score = 0;
                }

                return [
                    'index' => $item['index'],
                    'score' => $score,
                ];
            }
        );

        /**
         * Карта «индекс документа → числовой скор релевантности».
         *
         * @var array<int|string,int> $scores
         */
        $scores = [];

        foreach ($results as $result) {
            $scores[$result['index']] = $result['score'];
        }

        // Сортируем по убыванию score, сохраняя связи «индекс → оценка».
        arsort($scores);

        /**
         * Итоговый список документов после реранка.
         *
         * @var Document[] $rankedDocuments
         */
        $rankedDocuments = [];
        $counter         = 0;

        foreach (array_keys($scores) as $index) {
            if ($counter >= $this->topN) {
                break;
            }

            if (!isset($documents[$index])) {
                continue;
            }

            $document = $documents[$index];

            // Проставляем числовой скор в сам документ для дальнейшего использования.
            $document->setScore((float) $scores[$index]);

            $rankedDocuments[] = $document;
            $counter++;
        }

        return $rankedDocuments;
    }

    /**
     * Формирует текст промпта для оценки релевантности документа к вопросу пользователя.
     *
     * В промпт включаются:
     * - сам текст запроса пользователя;
     * - содержимое документа;
     * - явная инструкция вернуть только одно целое число 0–100.
     *
     * Чёткая формулировка уменьшает вероятность того, что модель вернёт
     * «разговорный» ответ вместо чистого числа.
     *
     * @param string $question Текст вопроса пользователя.
     * @param string $document Текст документа, который необходимо оценить.
     *
     * @return string Сформированный промпт для LLM.
     */
    protected function buildPrompt(string $question, string $document): string
    {
        return <<<PROMPT
Оцени релевантность следующего документа для запроса пользователя.
Ответь только одним целым числом от 0 до 100, где 0 — нерелевантно,
а 100 — максимально релевантно.

Запрос пользователя:
{$question}

Документ:
{$document}

Оценка (только число):
PROMPT;
    }

    /**
     * Разбирает и нормализует оценку релевантности из текста ответа модели.
     *
     * Логика разбора:
     * - если ответ пустой или равен null — возвращается 0;
     * - из текста регулярным выражением извлекаются все целые числа;
     * - берётся последнее найденное число (часто модели вначале пишут диапазон,
     *   вроде «0–100», а затем саму оценку — нас интересует именно последняя);
     * - значение приводится к целому числу и «зажимается» в диапазон 0–100,
     *   чтобы защититься от некорректных значений (например, -10 или 150).
     *
     * Таким образом, метод устойчив к «болтливым» ответам, где модель может
     * вернуть не только число, но и поясняющий текст.
     *
     * @param string|null $scoreText Текст ответа модели, из которого нужно
     *                               извлечь числовую оценку.
     *
     * @return int Нормализованная оценка релевантности в диапазоне 0–100.
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
