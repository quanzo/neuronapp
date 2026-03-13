<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\attachments\ImageFileAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextFileAttachmentDto;
use app\modules\neuron\interfaces\IAttachmentFile;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use Symfony\Component\Console\Output\OutputInterface;
use app\modules\neuron\classes\config\ConfigurationApp;


/**
 * Хелпер для построения DTO вложений (attachments) из путей к файлам.
 *
 * Используется консольными командами для преобразования значений опции
 * --file/-f в объекты из пространства имён dto\attachments.
 */
final class AttachmentHelper
{
    /**
     * Строит список DTO вложений по путям файлов.
     *
     * Каждый путь может быть абсолютным или относительным. Относительные пути
     * интерпретируются относительно текущей рабочей директории (getcwd()).
     * При любой ошибке (пустой путь, файл не найден или недоступен) в вывод
     * пишется сообщение об ошибке, а метод возвращает null.
     *
     * @param array<int,mixed> $paths  Сырые значения опции --file/-f.
     * @param OutputInterface  $output Консольный вывод для сообщений об ошибках.
     *
     * @return array<int,AttachmentDto>|null Массив DTO вложений или null при ошибке.
     */
    public static function buildAttachmentsFromPaths(array $paths, OutputInterface $output): ?array
    {
        $attachments = [];
        $cwd = getcwd() ?: '.';

        foreach ($paths as $rawPath) {
            if (!is_string($rawPath) || $rawPath === '') {
                $output->writeln('<error>Путь в опции --file не может быть пустым.</error>');
                return null;
            }

            $isAbsolute = str_starts_with($rawPath, DIRECTORY_SEPARATOR);
            $candidate = $isAbsolute ? $rawPath : $cwd . DIRECTORY_SEPARATOR . $rawPath;
            $real = realpath($candidate);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                $output->writeln(sprintf('<error>Файл "%s" не найден или недоступен.</error>', $rawPath));
                return null;
            }

            $attachments[] = self::resolveAttachmentDto($real);
        }

        return $attachments;
    }

    /**
     * Определяет подходящий класс DTO для указанного файла и создаёт его экземпляр.
     *
     * Распознаются базовые типы:
     * - изображения (png, jpg, jpeg, gif, webp, bmp) → ImageFileAttachmentDto;
     * - текст (txt, md, log, json, yaml, yml, xml, csv, ini, conf, cfg, без расширения) → TextFileAttachmentDto;
     * - прочие расширения по умолчанию считаются текстовыми.
     *
     * @param string $absolutePath Абсолютный путь к существующему файлу.
     *
     * @return AttachmentDto Экземпляр подходящего DTO вложения.
     */
    public static function resolveAttachmentDto(string $absolutePath): AttachmentDto
    {
        $ext = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        $basename = basename($absolutePath);

        $imageExtensions = [
            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp',
            'bmp',
        ];

        $textExtensions = [
            'txt',
            'md',
            'log',
            'json',
            'yaml',
            'yml',
            'xml',
            'csv',
            'ini',
            'conf',
            'cfg',
        ];

        if (in_array($ext, $imageExtensions, true)) {
            return new ImageFileAttachmentDto($absolutePath, $basename);
        }

        if (in_array($ext, $textExtensions, true) || $ext === '') {
            return new TextFileAttachmentDto($absolutePath, $basename);
        }

        // По умолчанию считаем файл текстовым, чтобы передать его содержимое в LLM.
        return new TextFileAttachmentDto($absolutePath, $basename);
    }

    /**
     * Удаляет дублирующиеся вложения перед отправкой сообщения.
     *
     * Для {@see AttachmentDto} дубликаты определяются по сочетанию:
     *  - класса вложения;
     *  - пути к файлу (если у DTO есть метод getPath());
     *  - хеша массива метаданных.
     *
     * Для {@see ContentBlockInterface} дубликаты определяются по идентификатору объекта
     * (т.е. один и тот же объект не будет добавлен более одного раза).
     *
     * @param array<int,AttachmentDto|ContentBlockInterface> $attachments
     *
     * @return array<int,AttachmentDto|ContentBlockInterface>
     */
    public static function deduplicateAttachments(array $attachments): array
    {
        $seen = [];
        $unique = [];

        foreach ($attachments as $attachment) {
            if ($attachment instanceof AttachmentDto) {
                $signature = get_class($attachment);

                if ($attachment instanceof IAttachmentFile) {
                    $path = $attachment->getPath();
                    if ($path) {
                        $signature .= '|' . $path;
                    }
                }

                $metadata = $attachment->getMetadata();
                if ($metadata) {
                    ksort($metadata);
                    $signature .= '|' . md5(serialize($metadata));
                }
            } elseif ($attachment instanceof ContentBlockInterface) {
                $signature = 'block|' . spl_object_id($attachment);
            } else {
                // Неподдерживаемый тип вложения — пропускаем.
                continue;
            }

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $unique[] = $attachment;
        }

        return $unique;
    }

    /**
     * Строит вложения по @-ссылкам в тексте с учётом настроек ConfigurationApp.
     *
     * Управляющие настройки читаются из config.jsonc через {@see ConfigurationApp::get()}:
     *  - context_files.enabled (bool, по умолчанию false);
     *  - context_files.max_total_size (int, байты, по умолчанию 1 MiB).
     *  - context_files.allowed_paths (array|string, список разрешённых путей/масок);
     *  - context_files.blocked_paths (array|string, список запрещённых путей/масок).
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

        $allowedPaths = $configApp->get('context_files.allowed_paths', []);
        $blockedPaths = $configApp->get('context_files.blocked_paths', []);

        if (is_string($allowedPaths) && $allowedPaths !== '') {
            $allowedPaths = [$allowedPaths];
        }
        if (!is_array($allowedPaths)) {
            $allowedPaths = [];
        }

        if (is_string($blockedPaths) && $blockedPaths !== '') {
            $blockedPaths = [$blockedPaths];
        }
        if (!is_array($blockedPaths)) {
            $blockedPaths = [];
        }

        $paths = FileContextHelper::extractFilePathsFromBody($body);
        if ($paths === []) {
            return ['attachments' => [], 'totalSize' => 0];
        }

        $dirPriority = $configApp->getDirPriority();

        $attachments = [];
        $totalSize   = 0;

        foreach ($paths as $relPath) {
            if ($allowedPaths !== [] && !self::matchesAnyMask($relPath, $allowedPaths)) {
                continue;
            }
            if ($blockedPaths !== [] && self::matchesAnyMask($relPath, $blockedPaths)) {
                continue;
            }

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

            $attachments[] = static::resolveAttachmentDto($resolved);
            $totalSize += $size;
        }

        /** @var list<AttachmentDto> $attachments */
        return ['attachments' => $attachments, 'totalSize' => $totalSize];
    }

    /**
     * Проверяет, соответствует ли путь хотя бы одному glob-шаблону.
     *
     * @param string        $path  Относительный путь
     * @param array<int,string> $masks Набор масок (glob)
     */
    private static function matchesAnyMask(string $path, array $masks): bool
    {
        foreach ($masks as $mask) {
            if (!is_string($mask) || $mask === '') {
                continue;
            }
            if (fnmatch($mask, $path)) {
                return true;
            }
        }
        return false;
    }
}
