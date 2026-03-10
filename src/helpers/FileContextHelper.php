<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\attachments\AttachmentDto;

/**
 * Хелпер для построения вложений (Attachments) из @-ссылок на файлы в тексте.
 *
 * Поддерживает следующий синтаксис:
 *  - строка начинается с символа '@' и далее идёт путь к файлу;
 *  - либо перед символом '@' стоит пробел, затем путь к файлу до первого пробела.
 *
 * По найденным путям файлы ищутся только в директориях, заданных в {@see DirPriority},
 * полученном из {@see ConfigurationApp}. Для каждого существующего файла создаётся
 * подходящий {@see AttachmentDto}, если в конфигурации приложения включена опция
 * context_files.enabled и не превышен лимит context_files.max_total_size.
 */
final class FileContextHelper
{
    /**
     * Извлекает пути к файлам из текста по синтаксису с символом '@'.
     *
     * Срабатывает, если:
     *  - '@' стоит в начале строки; или
     *  - перед '@' стоит пробел или табуляция.
     *
     * После '@' путь берётся до первого пробела/табуляции или конца строки.
     *
     * @param string $body Текст, в котором ищутся @-ссылки на файлы.
     *
     * @return list<string> Список путей без символа '@' (порядок следования в тексте).
     */
    public static function extractFilePathsFromBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $paths = [];

        if (preg_match_all('/(^|[ \t])@(?P<path>\S+)/m', $body, $matches) === 1 || !empty($matches['path'])) {
            foreach ($matches['path'] as $rawPath) {
                $trimmed = trim($rawPath);
                if ($trimmed !== '') {
                    $paths[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Строит вложения по @-ссылкам в тексте с учётом настроек ConfigurationApp.
     *
     * Управляющие настройки читаются из config.jsonc через {@see ConfigurationApp::get()}:
     *  - context_files.enabled (bool, по умолчанию false);
     *  - context_files.max_total_size (int, байты, по умолчанию 1 MiB).
     *
     * Для каждого найденного пути файл ищется через {@see DirPriority::resolveFile()}.
     * Если файл найден, существует и читается, и суммарный размер не превышает лимит,
     * создаётся {@see AttachmentDto} через {@see AttachmentHelper::resolveAttachmentDto()}.
     *
     * @param string           $body      Текст, из которого извлекаются @-ссылки.
     * @param ConfigurationApp $configApp Конфигурация приложения с DirPriority и настройками.
     *
     * @return array{attachments: list<AttachmentDto>, totalSize: int} Вложения и суммарный размер файлов.
     */
    public static function buildContextAttachments(string $body, ConfigurationApp $configApp): array
    {
        /** @var bool $enabled */
        $enabled = (bool) $configApp->get('context_files.enabled', false);
        if (!$enabled) {
            return ['attachments' => [], 'totalSize' => 0];
        }

        /** @var int $limit */
        $limit = (int) $configApp->get('context_files.max_total_size', 1048576);
        if ($limit <= 0) {
            return ['attachments' => [], 'totalSize' => 0];
        }

        $paths = self::extractFilePathsFromBody($body);
        if ($paths === []) {
            return ['attachments' => [], 'totalSize' => 0];
        }

        $dirPriority = $configApp->getDirPriority();

        $attachments = [];
        $totalSize   = 0;

        foreach ($paths as $relPath) {
            $resolved = $dirPriority->resolveFile($relPath);
            if ($resolved === null || !is_file($resolved) || !is_readable($resolved)) {
                continue;
            }

            $size = @filesize($resolved);
            if (!is_int($size) || $size < 0) {
                continue;
            }

            if ($totalSize + $size > $limit) {
                break;
            }

            $attachments[] = AttachmentHelper::resolveAttachmentDto($resolved);
            $totalSize += $size;
        }

        /** @var list<AttachmentDto> $attachments */
        return ['attachments' => $attachments, 'totalSize' => $totalSize];
    }
}
