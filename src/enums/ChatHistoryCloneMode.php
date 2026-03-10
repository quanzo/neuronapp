<?php
declare(strict_types=1);

namespace app\modules\neuron\enums;

/**
 * Режимы клонирования конфигурации агента с точки зрения истории чата.
 *
 * Используется в {@see \app\modules\neuron\classes\config\ConfigurationAgent::cloneForSession()}
 * для управления тем, как будет инициализирована история чата в клоне.
 */
enum ChatHistoryCloneMode: string
{
    /**
     * Создать для клона новую пустую оперативную историю {@see \NeuronAI\Chat\History\InMemoryChatHistory}.
     *
     * История оригинального агента игнорируется; клон начинается с \"чистого\" контекста.
     */
    case RESET_EMPTY = 'reset_empty';

    /**
     * Создать для клона новую оперативную историю {@see \NeuronAI\Chat\History\InMemoryChatHistory}
     * и перенести в неё все сообщения из исходной истории.
     *
     * Тип исходного хранилища (файловое, in-memory и т.д.) значения не имеет —
     * перенос выполняется через публичный интерфейс {@see \NeuronAI\Chat\History\ChatHistoryInterface}.
     */
    case COPY_CONTEXT = 'copy_context';
}

