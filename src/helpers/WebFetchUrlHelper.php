<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use function filter_var;
use function is_array;
use function parse_url;
use function preg_replace;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;

/**
 * Хелпер для URL-валидации и редирект-логики WebFetch.
 *
 * Вынесено отдельно, потому что логика пригодна и в других местах (валидатор URL,
 * политика «безопасных редиректов»), а также чтобы не раздувать сам Tool.
 *
 * Пример:
 *
 * <code>
 * $normalized = WebFetchUrlHelper::upgradeHttpToHttps('http://example.com');
 * $ok = WebFetchUrlHelper::isPermittedRedirect('https://example.com/a', 'https://www.example.com/b');
 * </code>
 */
final class WebFetchUrlHelper
{
    /**
     * Максимальная длина URL (грубая защита от чрезмерных payload / эксфильтрации).
     *
     * В Claude Code лимит значительно выше; здесь держим практичный предел и при
     * необходимости его можно поднять через параметр инструмента.
     */
    public const DEFAULT_MAX_URL_LENGTH = 2000;

    /**
     * Разрешённые схемы.
     *
     * @var string[]
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Проверяет, что URL:
     * - валиден и абсолютен;
     * - http/https;
     * - без username/password;
     * - (опционально) хост входит в allowlist.
     *
     * @param string   $url          URL
     * @param string[] $allowedHosts Белый список хостов (пустой = не ограничивать)
     * @param int      $maxLength    Максимальная длина URL
     *
     * @return string|null null если ok, иначе человекочитаемая ошибка
     */
    public static function validateUrl(
        string $url,
        array $allowedHosts = [],
        int $maxLength = self::DEFAULT_MAX_URL_LENGTH
    ): ?string {
        $url = trim($url);
        if ($url === '') {
            return 'URL не должен быть пустым.';
        }

        if (mb_strlen($url) > $maxLength) {
            return 'URL слишком длинный.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Некорректный URL. Ожидается абсолютный http/https URL.';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Не удалось разобрать URL.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $user = (string) ($parts['user'] ?? '');
        $pass = (string) ($parts['pass'] ?? '');

        if ($scheme === '' || !in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return 'Разрешены только схемы http и https.';
        }

        if ($host === '') {
            return 'В URL отсутствует хост.';
        }

        if ($user !== '' || $pass !== '') {
            return 'URL с username/password не поддерживается.';
        }

        if ($allowedHosts !== []) {
            $allowed = false;
            foreach ($allowedHosts as $allowedHost) {
                $allowedHostNormalized = strtolower(trim((string) $allowedHost));
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
     * Апгрейдит http → https (если схема http).
     *
     * Используется для:
     * - выравнивания поведения инструмента (по умолчанию тянуть https);
     * - снижения числа редиректов на сайтах, которые автоматически переводят на https.
     *
     * @param string $url Исходный URL
     *
     * @return string URL с https-схемой или исходная строка, если upgrade не применим.
     */
    public static function upgradeHttpToHttps(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http') {
            return $url;
        }

        return preg_replace('/^http:/i', 'https:', $url) ?? $url;
    }

    /**
     * Политика «безопасного редиректа»: разрешаем только если:
     * - схема и порт совпадают
     * - без username/password
     * - host совпадает с учётом «www.» варианта (example.com <-> www.example.com)
     *
     * Это соответствует принципу: не следовать «открытым редиректам» на другой домен,
     * потому что это может превратить доверенный URL в точку эксфильтрации данных.
     *
     * @param string $originalUrl URL, который мы запросили
     * @param string $redirectUrl URL из заголовка Location (после резолва в абсолютный)
     *
     * @return bool True если редирект разрешён (same-host с учётом www), иначе false.
     */
    public static function isPermittedRedirect(string $originalUrl, string $redirectUrl): bool
    {
        $o = parse_url($originalUrl);
        $r = parse_url($redirectUrl);
        if (!is_array($o) || !is_array($r)) {
            return false;
        }

        $oScheme = strtolower((string) ($o['scheme'] ?? ''));
        $rScheme = strtolower((string) ($r['scheme'] ?? ''));
        if ($oScheme === '' || $rScheme === '' || $oScheme !== $rScheme) {
            return false;
        }

        $oPort = (string) ($o['port'] ?? '');
        $rPort = (string) ($r['port'] ?? '');
        if ($oPort !== $rPort) {
            return false;
        }

        $rUser = (string) ($r['user'] ?? '');
        $rPass = (string) ($r['pass'] ?? '');
        if ($rUser !== '' || $rPass !== '') {
            return false;
        }

        $oHost = strtolower((string) ($o['host'] ?? ''));
        $rHost = strtolower((string) ($r['host'] ?? ''));
        if ($oHost === '' || $rHost === '') {
            return false;
        }

        $stripWww = static fn(string $h): string => preg_replace('/^www\./i', '', $h) ?? $h;
        return $stripWww($oHost) === $stripWww($rHost);
    }
}
