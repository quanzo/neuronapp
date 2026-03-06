<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата выполнения shell-команды ({@see \app\modules\neuron\tools\BashTool}).
 *
 * Инкапсулирует все данные о запуске команды: содержимое stdout и stderr,
 * код возврата процесса и признак принудительного завершения по таймауту.
 * При таймауте exitCode устанавливается в -1, а timedOut в true.
 * Если вывод превышает maxOutputSize, он обрезается с пометкой «[вывод обрезан]».
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'command'  => string,  // выполненная команда
 *     'stdout'   => string,  // стандартный вывод (может быть обрезан)
 *     'stderr'   => string,  // поток ошибок (может быть обрезан)
 *     'exitCode' => int,     // код завершения (-1 при таймауте/ошибке)
 *     'timedOut' => bool,    // была ли команда прервана по таймауту
 * ]
 * ```
 */
final class BashResultDto
{
    /**
     * @param string $command  Выполненная команда
     * @param string $stdout   Содержимое стандартного потока вывода
     * @param string $stderr   Содержимое потока ошибок
     * @param int    $exitCode Код завершения процесса
     * @param bool   $timedOut Была ли команда прервана по таймауту
     */
    public function __construct(
        public readonly string $command,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
        public readonly bool $timedOut,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{command: string, stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'exitCode' => $this->exitCode,
            'timedOut' => $this->timedOut,
        ];
    }
}
