<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use app\modules\neuron\classes\cache\ArrayCache;
use app\modules\neuron\classes\convert\Mdify;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\tools\HttpFetchRequestHeadersDto;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\HttpRequestHeadersFactory;
use app\modules\neuron\helpers\WebFetchUrlHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_change_key_case;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function strtoupper;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * WebFetchTool — извлечение содержимого из веб-страницы для LLM.
 *
 * Инструмент:
 * - делает HTTP GET к URL (с лимитом таймаута и размера тела);
 * - обрабатывает редиректы безопасно: разрешает только same-host (с учётом www),
 *   иначе возвращает текстовое сообщение-инструкцию перезапустить запрос на redirect URL;
 * - конвертирует HTML → markdown-like текст (через {@see Mdify::htmlToMarkdown()});
 * - опционально применяет `prompt` к извлечённому тексту через текущий LLM (через клон {@see ConfigurationAgent}).
 *
 * Ограничения:
 * - бинарный контент (например, PDF) не поддерживается: инструмент вернёт ошибку;
 * - аутентифицированные/private URL могут быть недоступны без специализированного MCP-инструмента.
 *
 * Пример:
 *
 * <code>
 * $tool = new WebFetchTool();
 * $text = $tool->__invoke('https://example.com', 'Суммаризируй ключевые тезисы');
 * </code>
 */
class WebFetchTool extends ATool
{
    /**
     * Режимы работы единого инструмента.
     */
    private const MODE_RAW = 'raw';

    private const MODE_EXTRACT = 'extract';

    private const MODE_PROMPT = 'prompt';

    /**
     * Максимальная длина извлечённого контента, передаваемого в LLM.
     *
     * Значение измеряется в символах (не токенах) и применяется как грубая защита
     * от слишком длинных документов. Перед обрезкой выполняется извлечение текста
     * из HTML/текста, затем результат ограничивается этим лимитом.
     */
    private int $maxContentChars = 100_000;

    /**
     * Таймаут по умолчанию (мс).
     *
     * Передаётся во внутренние методы fetch; может быть переопределён аргументом
     * `timeout_ms` при вызове инструмента.
     */
    private int $defaultTimeoutMs = 60_000;

    /**
     * Лимит тела ответа по умолчанию (байты).
     *
     * Важно: буферизация тела происходит в памяти, поэтому лимит нужен как защита
     * от чрезмерного потребления ресурсов.
     */
    private int $defaultMaxBodySize = 2_097_152; // 2 MiB

    /**
     * TTL кэша по умолчанию (сек).
     *
     * Если TTL = 0, кэширование отключается. Кэш является in-memory и живёт
     * только в рамках текущего процесса.
     */
    private int $defaultCacheTtlSeconds = 900;

    /**
     * Максимальное число редиректов для режима raw (по умолчанию имитируем HttpFetchTool).
     */
    private int $defaultRawMaxRedirects = 5;

    /**
     * Белый список хостов. Пустой массив означает отсутствие ограничения.
     *
     * @var string[]
     */
    private array $allowedHosts = [];

    /**
     * HTTP клиент (Amp).
     *
     * Инициализируется лениво при первом сетевом запросе, чтобы:
     * - не создавать тяжёлые объекты при регистрации инструментов;
     * - позволить тестам подменять {@see WebFetchTool::fetchOnce()} без инициализации клиента.
     */
    private ?HttpClient $httpClient;

    /**
     * Исходящие HTTP-заголовки (после слияния с дефолтами Firefox, если переданы свои).
     *
     * Важно: DTO гарантирует, что значение заголовков будет очищено от CR/LF, чтобы
     * исключить инъекцию дополнительных заголовков.
     */
    private HttpFetchRequestHeadersDto $defaultRequestHeaders;

    /**
     * In-memory LRU cache (process-local).
     *
     * Реализация кэша — {@see ArrayCache}, совместимая с PSR-6. Ключ — хэш,
     * чтобы соответствовать ограничениям формата ключей.
     */
    private static ?ArrayCache $cache = null;

    /**
     * Создаёт инструмент WebFetch.
     *
     * @param string          $name        Имя инструмента (используется LLM для вызова)
     * @param string          $description Описание инструмента в системном промпте
     * @param HttpClient|null $httpClient  Опционально: явная зависимость для HTTP (для DI/тестов).
     *                                    Если не передана — клиент создаётся лениво при первом fetch.
     * @param HttpFetchRequestHeadersDto|null $requestHeaders Дополнительные заголовки; сливаются с
     *        {@see HttpFetchRequestHeadersDto::firefoxDefaults()}, перекрывая совпадающие имена
     */
    public function __construct(
        string $name = 'web_fetch',
        string $description = 'Получить контент по URL (HTML→markdown) и (опционально) применить prompt; редиректы на другой host не следуются автоматически.',
        ?HttpClient $httpClient = null,
        ?HttpFetchRequestHeadersDto $requestHeaders = null,
    ) {
        parent::__construct(name: $name, description: $description);
        $this->httpClient = $httpClient;
        $this->defaultRequestHeaders = HttpRequestHeadersFactory::firefoxMerged($requestHeaders);

        if (self::$cache === null) {
            self::$cache = new ArrayCache(limit: 128);
        }
    }

    /**
     * Устанавливает белый список хостов.
     *
     * @param string[] $allowedHosts
     *
     * @return self
     */
    public function setAllowedHosts(array $allowedHosts): self
    {
        $this->allowedHosts = $allowedHosts;
        return $this;
    }

    /**
     * Устанавливает TTL кэша по умолчанию.
     */
    public function setDefaultCacheTtlSeconds(int $seconds): self
    {
        $this->defaultCacheTtlSeconds = max(0, $seconds);
        return $this;
    }

    /**
     * Устанавливает лимит максимального размера тела ответа по умолчанию.
     */
    public function setDefaultMaxBodySize(int $bytes): self
    {
        $this->defaultMaxBodySize = max(1024, $bytes);
        return $this;
    }

    /**
     * Устанавливает таймаут запроса по умолчанию.
     */
    public function setDefaultTimeoutMs(int $timeoutMs): self
    {
        $this->defaultTimeoutMs = max(1_000, $timeoutMs);
        return $this;
    }

    /**
     * Задаёт исходящие HTTP-заголовки, объединяя их с дефолтами Firefox (перекрытие по имени).
     *
     * @param HttpFetchRequestHeadersDto $requestHeaders Набор заголовков для слияния с Firefox
     *
     * @return self
     */
    public function setDefaultRequestHeaders(HttpFetchRequestHeadersDto $requestHeaders): self
    {
        $this->defaultRequestHeaders = HttpRequestHeadersFactory::firefoxMerged($requestHeaders);
        return $this;
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'mode',
                type: PropertyType::STRING,
                description: 'Режим: raw (HTTP JSON), extract (HTML→text), prompt (extract + LLM). По умолчанию extract.',
                required: false,
            ),
            ToolProperty::make(
                name: 'url',
                type: PropertyType::STRING,
                description: 'Абсолютный URL (http/https). HTTP будет автоматически повышен до HTTPS.',
                required: true,
            ),
            ToolProperty::make(
                name: 'method',
                type: PropertyType::STRING,
                description: 'HTTP-метод для режима raw: GET или HEAD (по умолчанию GET).',
                required: false,
            ),
            ToolProperty::make(
                name: 'prompt',
                type: PropertyType::STRING,
                description: 'Инструкция/вопрос к извлечённому контенту (если задано — применится LLM). Если не задано — вернётся извлечённый текст.',
                required: false,
            ),
            ToolProperty::make(
                name: 'timeout_ms',
                type: PropertyType::INTEGER,
                description: 'Таймаут запроса в миллисекундах (опционально).',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_body_size',
                type: PropertyType::INTEGER,
                description: 'Максимальный размер тела ответа в байтах (опционально).',
                required: false,
            ),
            ToolProperty::make(
                name: 'cache_ttl_seconds',
                type: PropertyType::INTEGER,
                description: 'TTL кэша в секундах (опционально). 0 отключает кэш.',
                required: false,
            ),
            ToolProperty::make(
                name: 'follow_redirects',
                type: PropertyType::BOOLEAN,
                description: 'Только для режима raw: следовать редиректам автоматически (как HttpFetchTool). По умолчанию true.',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_redirects',
                type: PropertyType::INTEGER,
                description: 'Только для режима raw: максимум редиректов (по умолчанию 5).',
                required: false,
            ),
        ];
    }

    /**
     * Выполняет web fetch и возвращает plain-text.
     *
     * Алгоритм:
     * - валидирует URL и применяет allowlist хостов;
     * - нормализует URL (http → https);
     * - проверяет кэш (если включён);
     * - выполняет HTTP GET с безопасной политикой редиректов;
     * - проверяет код ответа и Content-Type;
     * - извлекает читаемый текст (HTML → markdown-like);
     * - если задан `prompt` — отправляет извлечённый контент в LLM (через клон агента без tools);
     * - сохраняет результат в кэш (если включён) и возвращает plain-text.
     *
     * @param string|null $mode Режим: raw|extract|prompt
     * @param string      $url URL
     * @param string|null $method HTTP метод (для raw)
     * @param string|null $prompt Prompt для prompt-режима
     * @param int|null    $timeout_ms Таймаут запроса, мс
     * @param int|null    $max_body_size Лимит тела ответа, байты
     * @param int|null    $cache_ttl_seconds TTL кэша (для extract/prompt), сек
     * @param bool|null   $follow_redirects Следовать редиректам (только raw)
     * @param int|null    $max_redirects Максимум редиректов (только raw)
     *
     * @return string
     *  - для raw: JSON (в стиле HttpFetchTool: url/status/headers/body/truncated)
     *  - для extract/prompt: plain-text (как прежний WebFetchTool)
     */
    public function __invoke(
        ?string $mode,
        string $url,
        ?string $method = null,
        ?string $prompt = null,
        ?int $timeout_ms = null,
        ?int $max_body_size = null,
        ?int $cache_ttl_seconds = null,
        ?bool $follow_redirects = null,
        ?int $max_redirects = null,
    ): string {
        $modeNormalized = strtolower(trim((string) ($mode ?? self::MODE_EXTRACT)));
        if (!in_array($modeNormalized, [self::MODE_RAW, self::MODE_EXTRACT, self::MODE_PROMPT], true)) {
            return 'Error: Unsupported mode. Use raw|extract|prompt.';
        }

        $validationError = WebFetchUrlHelper::validateUrl($url, $this->allowedHosts);
        if ($validationError !== null) {
            return 'Error: ' . $validationError;
        }

        $effectiveTimeoutMs = $timeout_ms ?? $this->defaultTimeoutMs;
        $effectiveMaxBodySize = $max_body_size ?? $this->defaultMaxBodySize;
        $effectiveTtl = $cache_ttl_seconds ?? $this->defaultCacheTtlSeconds;

        $normalizedUrl = WebFetchUrlHelper::upgradeHttpToHttps($url);
        $cacheKey = $this->buildCacheKey(
            $normalizedUrl,
            $modeNormalized . '|' . (string) ($method ?? '') . '|' . (string) ($prompt ?? ''),
            $effectiveTimeoutMs,
            $effectiveMaxBodySize
        );

        // Кэшируем только extract/prompt (raw-режим обычно используется для дебага и должен быть "живым").
        if ($modeNormalized !== self::MODE_RAW && $effectiveTtl > 0 && self::$cache !== null) {
            $cached = self::$cache->getItem($cacheKey)->get();
            if (is_array($cached) && isset($cached['result']) && is_string($cached['result'])) {
                return $cached['result'];
            }
        }

        if ($modeNormalized === self::MODE_RAW) {
            return $this->rawFetchAsJson(
                url: $normalizedUrl,
                method: $method,
                timeoutMs: $effectiveTimeoutMs,
                maxBodySize: $effectiveMaxBodySize,
                followRedirects: $follow_redirects,
                maxRedirects: $max_redirects
            );
        }

        $response = $this->fetchWithRedirectPolicy($normalizedUrl, $effectiveTimeoutMs, $effectiveMaxBodySize);
        if (isset($response['redirect']) && is_array($response['redirect'])) {
            $msg = $this->formatRedirectMessage(
                $url,
                $response['redirect']['redirectUrl'] ?? '',
                (int) ($response['redirect']['statusCode'] ?? 0),
                (string) ($prompt ?? '')
            );
            $this->saveCacheIfNeeded($effectiveTtl, $cacheKey, $msg);
            return $msg;
        }

        $body = (string) ($response['body'] ?? '');
        $contentType = strtolower((string) ($response['contentType'] ?? ''));
        $status = (int) ($response['status'] ?? 0);
        $reason = (string) ($response['reason'] ?? '');

        if ($status <= 0) {
            $msg = 'Error: Не удалось выполнить запрос.';
            $this->saveCacheIfNeeded($effectiveTtl, $cacheKey, $msg);
            return $msg;
        }

        if ($status >= 400) {
            $msg = 'Error: HTTP ' . $status . ($reason !== '' ? ' ' . $reason : '');
            $this->saveCacheIfNeeded($effectiveTtl, $cacheKey, $msg);
            return $msg;
        }

        if (!$this->isSupportedContentType($contentType)) {
            $msg = 'Error: Бинарный или неподдерживаемый Content-Type: ' . ($contentType !== '' ? $contentType : '[unknown]');
            $this->saveCacheIfNeeded($effectiveTtl, $cacheKey, $msg);
            return $msg;
        }

        $extracted = $this->extractMarkdownLikeText($body, $contentType);
        $extracted = $this->capContent($extracted);

        $result = $modeNormalized === self::MODE_PROMPT
            ? $this->applyPromptViaLlm((string) ($prompt ?? ''), $extracted)
            : $extracted;

        $this->saveCacheIfNeeded($effectiveTtl, $cacheKey, $result);
        return $result;
    }

    /**
     * Режим raw: вернуть структурированный JSON (как HttpFetchTool).
     *
     * @return string JSON
     */
    private function rawFetchAsJson(
        string $url,
        ?string $method,
        int $timeoutMs,
        int $maxBodySize,
        ?bool $followRedirects,
        ?int $maxRedirects
    ): string {
        $methodNormalized = strtoupper(trim((string) ($method ?? 'GET')));
        if (!in_array($methodNormalized, ['GET', 'HEAD'], true)) {
            return '{"error":"Поддерживаются только методы GET и HEAD."}';
        }

        $follow = $followRedirects ?? true;
        $maxR = $maxRedirects ?? $this->defaultRawMaxRedirects;

        $currentUrl = $url;
        $redirects = 0;
        $last = null;

        while (true) {
            $last = $this->fetchOnce($currentUrl, $timeoutMs, $maxBodySize, $methodNormalized === 'HEAD');

            $status = (int) ($last['status'] ?? 0);
            if (!$follow || !in_array($status, [301, 302, 307, 308], true)) {
                break;
            }

            $location = (string) ($last['headers']['location'] ?? '');
            if ($location === '') {
                break;
            }

            $redirectUrl = $this->resolveRedirectUrl($currentUrl, $location);
            if ($redirectUrl === '') {
                break;
            }

            $redirects++;
            if ($redirects > $maxR) {
                break;
            }

            $currentUrl = $redirectUrl;
        }

        $headers = $last['headers'] ?? [];
        $body = (string) ($last['body'] ?? '');
        $truncated = mb_strlen($body) >= $maxBodySize;

        // Пишем JSON вручную, чтобы не тянуть DTO. Формат совместим с HttpFetchTool по ключам.
        $payload = [
            'url' => $url,
            'final_url' => $currentUrl,
            'status' => (int) ($last['status'] ?? 0),
            'reason' => (string) ($last['reason'] ?? ''),
            'headers' => $headers,
            'body' => $body,
            'truncated' => $truncated,
            'redirects' => $redirects,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"json_encode failed"}';
    }

    /**
     * Выполняет GET с ручной обработкой редиректов по правилам безопасности.
     *
     * Поведение:
     * - редиректы 301/302/307/308 обрабатываются вручную;
     * - follow разрешён только если {@see WebFetchUrlHelper::isPermittedRedirect()} вернул true;
     * - относительный Location резолвится относительно текущего URL;
     * - при редиректе на другой host возвращается структура `redirect`, чтобы вызывающий метод
     *   сформировал инструкцию пользователю перезапустить инструмент с новым URL.
     *
     * @return array{status?:int,reason?:string,headers?:array<string,string|array>,contentType?:string,body?:string,redirect?:array<string,mixed>}
     */
    protected function fetchWithRedirectPolicy(string $url, int $timeoutMs, int $maxBodySize): array
    {
        $currentUrl = $url;
        $maxRedirects = 10;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $response = $this->fetchOnce($currentUrl, $timeoutMs, $maxBodySize);
            $status = (int) ($response['status'] ?? 0);

            if (!in_array($status, [301, 302, 307, 308], true)) {
                return $response;
            }

            $location = (string) ($response['headers']['location'] ?? '');
            if ($location === '') {
                return [
                    'status' => $status,
                    'reason' => (string) ($response['reason'] ?? ''),
                    'headers' => (array) ($response['headers'] ?? []),
                    'contentType' => (string) ($response['contentType'] ?? ''),
                    'body' => '',
                ];
            }

            $redirectUrl = $this->resolveRedirectUrl($currentUrl, $location);
            if ($redirectUrl === '') {
                return [
                    'redirect' => [
                        'originalUrl' => $currentUrl,
                        'redirectUrl' => $location,
                        'statusCode' => $status,
                    ],
                ];
            }

            if (!WebFetchUrlHelper::isPermittedRedirect($currentUrl, $redirectUrl)) {
                return [
                    'redirect' => [
                        'originalUrl' => $currentUrl,
                        'redirectUrl' => $redirectUrl,
                        'statusCode' => $status,
                    ],
                ];
            }

            $currentUrl = $redirectUrl;
        }

        return [
            'status' => 0,
            'reason' => 'Too many redirects',
            'headers' => [],
            'contentType' => '',
            'body' => '',
        ];
    }

    /**
     * Выполняет один HTTP GET.
     *
     * Вынесено в protected для тестов (можно переопределить без реального HTTP).
     *
     * Ограничение размера тела применяется после буферизации ответа и является
     * дополнительной страховкой (в зависимости от реализации транспорта тело может быть
     * больше ожидаемого).
     *
     * @return array{status:int,reason:string,headers:array<string,string>,contentType:string,body:string}
     */
    protected function fetchOnce(string $url, int $timeoutMs, int $maxBodySize, bool $isHead = false): array
    {
        $client = $this->httpClient ??= HttpClientBuilder::buildDefault();

        $request = new Request($url, $isHead ? 'HEAD' : 'GET');
        foreach (explode("\r\n", trim($this->defaultRequestHeaders->toStreamHeaderString())) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            $request->setHeader($name, $value);
        }

        // Для WebFetch явно просим текстовые форматы.
        $request->setHeader('Accept', 'text/markdown, text/html, */*');

        // В Amp timeout задаётся через опции клиента/сокетов; для простоты держим
        // таймауты на уровне окружения Amp и ограничиваемся maxBodySize.
        $response = $client->request($request);

        $status = $response->getStatus();
        $reason = $response->getReason() ?? '';
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $contentType = '';
        if (isset($headers['content-type'])) {
            $contentType = is_array($headers['content-type']) ? (string) ($headers['content-type'][0] ?? '') : (string) $headers['content-type'];
        }

        $body = $isHead ? '' : $response->getBody()->buffer();
        if (strlen($body) > $maxBodySize) {
            $body = substr($body, 0, $maxBodySize);
        }

        return [
            'status' => $status,
            'reason' => (string) $reason,
            'headers' => $headers,
            'contentType' => (string) $contentType,
            'body' => (string) $body,
        ];
    }

    /**
     * Применяет prompt к извлечённому контенту через LLM.
     *
     * Вынесено в protected для тестов (можно переопределить без реального LLM).
     *
     * Важно:
     * - используется клон {@see ConfigurationAgent} с пустой историей ({@see ChatHistoryCloneMode::RESET_EMPTY});
     * - инструменты отключены, чтобы исключить побочные эффекты и рекурсию;
     * - ожидается plain-text ответ.
     *
     * @param string $prompt  Инструкция пользователя (что извлечь/как обработать)
     * @param string $content Извлечённый текст страницы (уже обрезанный по лимитам)
     *
     * @return string Plain-text ответ модели (может быть пустым при некорректном ответе).
     */
    protected function applyPromptViaLlm(string $prompt, string $content): string
    {
        $agentCfg = $this->getAgentCfg();
        if (!$agentCfg instanceof ConfigurationAgent) {
            return $this->formatNoAgentError();
        }

        $clone = $agentCfg->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY);
        $clone->tools = [];
        $clone->toolMaxTries = 0;
        $clone->instructions = self::buildWebFetchInstructions();

        $userPrompt = implode("\n\n", [
            "Web page content (extracted):\n---\n" . $content . "\n---",
            $prompt,
            'Ответь кратко и по делу. Возвращай только текст.',
        ]);

        $response = $clone->sendMessage(new Message(MessageRole::USER, $userPrompt));
        if ($response instanceof Message) {
            return trim((string) ($response->getContent() ?? ''));
        }

        if (is_string($response)) {
            return trim($response);
        }

        if (is_object($response) && method_exists($response, '__toString')) {
            return trim((string) $response);
        }

        return '';
    }

    /**
     * Строит системные инструкции для LLM-обработки webfetch.
     *
     * Инструкции сделаны «жёсткими», чтобы:
     * - не допустить вызовов инструментов;
     * - удерживать ответ в plain-text;
     * - снизить галлюцинации и побочные предположения.
     *
     * @return string Текст системного промпта (инструкций) для {@see ConfigurationAgent::$instructions}.
     */
    private static function buildWebFetchInstructions(): string
    {
        return implode("\n", [
            'Ты — помощник, который отвечает на вопрос пользователя по содержимому веб-страницы.',
            '',
            'Правила:',
            '- Не вызывай инструменты.',
            '- Ответь только текстом.',
            '- Опирайся на предоставленный контент.',
            '- Не выдумывай факты, которых нет в контенте.',
        ]);
    }

    /**
     * Извлекает «markdown-like» текст из тела ответа, исходя из Content-Type.
     *
     * @param string $body        Сырые bytes/строка тела ответа (в текущей реализации — строка)
     * @param string $contentType Content-Type ответа (lower/upper не важен)
     *
     * @return string Текст, пригодный для передачи в LLM или возврата пользователю.
     */
    private function extractMarkdownLikeText(string $body, string $contentType): string
    {
        if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml')) {
            return Mdify::htmlToMarkdown($body);
        }

        return $body;
    }

    /**
     * Обрезает контент до лимита символов, добавляя понятный маркер обрезки.
     *
     * @param string $text Исходный текст
     *
     * @return string Обрезанный (или исходный) текст.
     */
    private function capContent(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $this->maxContentChars) {
            return $text;
        }
        return mb_substr($text, 0, $this->maxContentChars) . "\n\n[Content truncated due to length...]";
    }

    /**
     * Определяет, является ли Content-Type допустимым для обработки как текст.
     *
     * Правило (как было согласовано):
     * - допускаем `text/*` и ряд «текстовых» application/* типов (json/xml/xhtml);
     * - всё остальное считаем бинарным/неподдерживаемым.
     *
     * @param string $contentType Content-Type заголовка ответа
     *
     * @return bool True если можно обрабатывать как текст, иначе false.
     */
    private function isSupportedContentType(string $contentType): bool
    {
        $contentType = strtolower(trim($contentType));
        if ($contentType === '') {
            // Если сервер не указал тип, считаем это текстом (best effort).
            return true;
        }

        if (str_starts_with($contentType, 'text/')) {
            return true;
        }

        return str_contains($contentType, 'application/json')
            || str_contains($contentType, 'application/xml')
            || str_contains($contentType, 'application/xhtml+xml');
    }

    /**
     * Формирует человекочитаемое сообщение для случая «редирект на другой host».
     *
     * Сообщение специально сделано в виде инструкции, чтобы модель могла
     * повторить вызов инструмента с новым URL, не следуя редиректу автоматически.
     *
     * @param string $originalUrl  Исходный URL (как был вызван инструмент)
     * @param string $redirectUrl  URL из Location (абсолютный или уже резолвленный)
     * @param int    $statusCode   HTTP код редиректа
     * @param string $prompt       Исходный prompt (прокидываем, чтобы перезапуск был «детерминированным»)
     *
     * @return string Текст сообщения-инструкции.
     */
    private function formatRedirectMessage(string $originalUrl, string $redirectUrl, int $statusCode, string $prompt): string
    {
        $statusText = match ($statusCode) {
            301 => 'Moved Permanently',
            308 => 'Permanent Redirect',
            307 => 'Temporary Redirect',
            302 => 'Found',
            default => 'Redirect',
        };

        return implode("\n", [
            'REDIRECT DETECTED: The URL redirects to a different host.',
            '',
            'Original URL: ' . $originalUrl,
            'Redirect URL: ' . $redirectUrl,
            'Status: ' . $statusCode . ' ' . $statusText,
            '',
            'To complete your request, run WebFetch again with these parameters:',
            '- url: "' . $redirectUrl . '"',
            '- prompt: "' . $prompt . '"',
        ]);
    }

    /**
     * Резолвит значение Location в абсолютный URL.
     *
     * Поддерживаемые варианты:
     * - абсолютный URL (http/https) — возвращается как есть;
     * - абсолютный путь (`/path`) — склеивается с scheme/host/port базового URL;
     * - относительный путь (`path`) — склеивается с директорией базового пути.
     *
     * @param string $baseUrl   URL, относительно которого резолвим Location
     * @param string $location  Значение заголовка Location
     *
     * @return string Абсолютный URL или пустая строка, если резолв не удался.
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }

        // Абсолютный URL.
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        // Относительный URL: Amp сам не резолвит, делаем минимально.
        // parse_url+сборка достаточно для наших тестовых/практичных кейсов.
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';

        if ($scheme === '' || $host === '') {
            return '';
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $dir = '/';
        if ($path !== '') {
            $pos = strrpos($path, '/');
            $dir = $pos === false ? '/' : substr($path, 0, $pos + 1);
        }

        return $scheme . '://' . $host . $port . $dir . $location;
    }

    /**
     * Строит ключ кэша для результата WebFetch.
     *
     * Используем sha1, потому что PSR-6 ключи имеют ограничения на символы, а
     * исходные параметры могут содержать `://`, `?`, `&` и т.п.
     *
     * @param string $url         Нормализованный URL (после upgrade http→https)
     * @param string $prompt      Prompt (может быть пустым)
     * @param int    $timeoutMs   Таймаут (мс)
     * @param int    $maxBodySize Лимит тела ответа (байты)
     *
     * @return string PSR-6 совместимый ключ кэша.
     */
    private function buildCacheKey(string $url, string $prompt, int $timeoutMs, int $maxBodySize): string
    {
        // PSR-6 ключи имеют ограничения на символы, поэтому хэш.
        return 'webfetch_' . sha1(implode('|', [$url, $prompt, (string) $timeoutMs, (string) $maxBodySize]));
    }

    /**
     * Сохраняет результат в кэш, если TTL включён.
     *
     * @param int    $ttl      TTL в секундах (0 = не кэшировать)
     * @param string $cacheKey Ключ кэша (см. {@see WebFetchTool::buildCacheKey()})
     * @param string $result   Итоговый plain-text результат (успех или error-текст)
     */
    private function saveCacheIfNeeded(int $ttl, string $cacheKey, string $result): void
    {
        if ($ttl <= 0 || self::$cache === null) {
            return;
        }

        $item = self::$cache->getItem($cacheKey);
        $item->set(['result' => $result])->expiresAfter($ttl);
        self::$cache->save($item);
    }

    /**
     * Сообщение об ошибке конфигурации: когда инструмент пытается применить prompt
     * без доступного {@see ConfigurationAgent}.
     *
     * Это возможно, если инструмент используется вне стандартного пути сборки tools,
     * или если забыли вызвать {@see ATool::setAgentCfg()} при создании экземпляра.
     *
     * @return string Человекочитаемое error-сообщение.
     */
    private function formatNoAgentError(): string
    {
        return 'Error: WebFetchTool requires agent configuration to apply prompt.';
    }
}
