<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\attachments\AttachmentDto;
use app\modules\neuron\classes\dto\attachments\ImageFileAttachmentDto;
use app\modules\neuron\classes\dto\attachments\TextFileAttachmentDto;
use app\modules\neuron\interfaces\IAttachmentFile;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
}
