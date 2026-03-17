<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\HttpFetchResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function explode;
use function fopen;
use function fgets;
use function filter_var;
use function fclose;
use function json_encode;
use function parse_url;
use function rtrim;
use function stream_context_create;
use function stream_get_meta_data;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;
use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент безопасного HTTP-запроса (fetch) для LLM.
 *
 * Поддерживает методы GET и HEAD, ограничение списка доменов, а также лимиты
 * по времени и размеру тела ответа. Возвращает структурированный результат
 * через {@see HttpFetchResultDto}.
 */
class HttpFetchTool extends ATool
{
    /**
     * Разрешённые схемы URL.
     *
     * @var string[]
     */
    protected array $allowedSchemes = ['http', 'https'];

    /**
     * Белый список хостов. Пустой массив означает, что хост не ограничен.
     *
     * @var string[]
     */
    protected array $allowedHosts = [];

    /**
     * Таймаут соединения и чтения (секунды).
     */
    protected int $defaultTimeout = 10;

    /**
     * Лимит размера тела ответа (байты).
     */
    protected int $defaultMaxBodySize = 262144;

    /**
     * @param string   $name        Имя инструмента
     * @param string   $description Описание инструмента
     * @param string[] $allowedHosts Белый список хостов (опционально)
     * @param int      $defaultTimeout Таймаут по умолчанию (секунды)
     * @param int      $defaultMaxBodySize Лимит тела по умолчанию (байты)
     */
    public function __construct(
        string $name = 'http_fetch',
        string $description = 'Безопасный HTTP-запрос (GET/HEAD) с ограничением доменов и размера ответа.',
        array $allowedHosts = [],
        int $defaultTimeout = 10,
        int $defaultMaxBodySize = 262144,
    ) {
        parent::__construct(name: $name, description: $description);
        $this->allowedHosts = $allowedHosts;
        $this->defaultTimeout = $defaultTimeout;
        $this->defaultMaxBodySize = $defaultMaxBodySize;
    }

    /**
     * Описание входных параметров инструмента для LLM.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'url',
                type: PropertyType::STRING,
                description: 'Абсолютный URL (http/https), к которому нужно выполнить запрос.',
                required: true,
            ),
            ToolProperty::make(
                name: 'method',
                type: PropertyType::STRING,
                description: 'HTTP-метод: GET или HEAD (по умолчанию GET).',
                required: false,
            ),
            ToolProperty::make(
                name: 'timeout',
                type: PropertyType::INTEGER,
                description: 'Таймаут запроса в секундах (опционально).',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_body_size',
                type: PropertyType::INTEGER,
                description: 'Максимальный размер тела ответа в байтах (опционально).',
                required: false,
            ),
        ];
    }

    /**
     * Выполняет HTTP-запрос и возвращает результат в виде JSON.
     *
     * @param string   $url          Целевой URL
     * @param string|null $method    HTTP-метод (GET/HEAD)
     * @param int|null $timeout      Таймаут запроса (секунды)
     * @param int|null $max_body_size Лимит тела (байты)
     *
     * @return string JSON-строка с результатом {@see HttpFetchResultDto::toArray()}
     */
    public function __invoke(
        string $url,
        ?string $method = null,
        ?int $timeout = null,
        ?int $max_body_size = null
    ): string {
        $validationError = $this->validateUrl($url);
        if ($validationError !== null) {
            return json_encode(
                [
                    'error' => $validationError,
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        $methodNormalized = strtoupper($method ?? 'GET');
        if (!in_array($methodNormalized, ['GET', 'HEAD'], true)) {
            return json_encode(
                [
                    'error' => 'Поддерживаются только методы GET и HEAD.',
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $effectiveMaxBody = $max_body_size ?? $this->defaultMaxBodySize;

        $context = stream_context_create(
            [
                'http' => [
                    'method' => $methodNormalized,
                    'follow_location' => 1,
                    'max_redirects' => 5,
                    'timeout' => $effectiveTimeout,
                    'ignore_errors' => true,
                ],
            ]
        );

        $resource = @fopen($url, 'r', false, $context);
        if ($resource === false) {
            return json_encode(
                [
                    'error' => 'Не удалось открыть URL или произошла сетевая ошибка.',
                ],
                JSON_UNESCAPED_UNICODE
            );
        }

        $meta = stream_get_meta_data($resource);
        $headers = $this->parseHeaders($meta['wrapper_data'] ?? []);

        $statusCode = 0;
        if (isset($headers[':status'])) {
            $statusCode = (int) $headers[':status'];
        }

        $body = '';
        $truncated = false;
        if ($methodNormalized !== 'HEAD') {
            while (!feof($resource)) {
                $chunk = fgets($resource);
                if ($chunk === false) {
                    break;
                }
                if (strlen($body) + strlen($chunk) > $effectiveMaxBody) {
                    $body .= substr($chunk, 0, $effectiveMaxBody - strlen($body));
                    $truncated = true;
                    break;
                }
                $body .= $chunk;
            }
        }

        fclose($resource);

        $resultDto = new HttpFetchResultDto(
            $url,
            $statusCode,
            $headers,
            $body,
            $truncated
        );

        return json_encode($resultDto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Устанавливает белый список хостов.
     *
     * @param string[] $allowedHosts Список разрешённых хостов
     *
     * @return self
     */
    public function setAllowedHosts(array $allowedHosts): self
    {
        $this->allowedHosts = $allowedHosts;

        return $this;
    }

    /**
     * Устанавливает таймаут по умолчанию для HTTP-запросов.
     *
     * @return self
     */
    public function setDefaultTimeout(int $defaultTimeout): self
    {
        $this->defaultTimeout = $defaultTimeout;

        return $this;
    }

    /**
     * Устанавливает лимит размера тела ответа по умолчанию.
     *
     * @return self
     */
    public function setDefaultMaxBodySize(int $defaultMaxBodySize): self
    {
        $this->defaultMaxBodySize = $defaultMaxBodySize;

        return $this;
    }

    /**
     * Валидация URL и проверка на соответствие белому списку.
     */
    private function validateUrl(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Некорректный URL. Ожидается абсолютный http/https URL.';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Не удалось разобрать URL.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, $this->allowedSchemes, true)) {
            return 'Разрешены только схемы http и https.';
        }

        if ($this->allowedHosts !== [] && $host !== '') {
            $allowed = false;
            foreach ($this->allowedHosts as $allowedHost) {
                $allowedHostNormalized = strtolower(trim($allowedHost));
                if ($allowedHostNormalized !== '' && $host === $allowedHostNormalized) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return 'Хост не входит в список разрешённых.';
            }
        }

        return null;
    }

    /**
     * Разбор заголовков ответа в ассоциативный массив.
     *
     * @param string[] $rawHeaders Сырые строки заголовков
     *
     * @return array<string,string>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $result = [];

        foreach ($rawHeaders as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'HTTP/')) {
                $parts = explode(' ', $line, 3);
                if (isset($parts[1])) {
                    $result[':status'] = $parts[1];
                }
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            if ($name === '') {
                continue;
            }

            if (isset($result[$name])) {
                $result[$name] = rtrim($result[$name] . ', ' . $value, ', ');
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
