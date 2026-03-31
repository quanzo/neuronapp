<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\summarize;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\ChatHistoryToolMessageHelper;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use Psr\Log\LoggerInterface;

use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function mb_strlen;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Сервис суммаризации сообщений одного шага и применения результата к истории.
 *
 * Назначение (контракт высокого уровня):
 * - входом служат **сообщения одного шага** (дельта истории между two snapshots) и ссылки на историю/агента;
 * - сервис приводит дельту к “чистому” транскрипту (фильтры шума, дедупликация);
 * - затем получает контент summary:
 *   - либо через {@see Skill} (вызов LLM) при {@see SummarizeService::$useSkill},
 *   - либо fallback-ом: сам транскрипт становится summary (без вызова LLM);
 * - затем применяет результат к истории одним из режимов:
 *   - `replace_range` (по умолчанию): заменить сообщения шага одним summary-сообщением;
 *   - `append_summary`: не удалять сообщения шага, а добавить summary отдельным сообщением.
 *
 * Важные принципы:
 * - сервис **не знает** про оркестратор или todo-листы: он работает с абстракциями “история + дельта”;
 * - сервис не вычисляет snapshots сам: вычисление `countBefore/countAfter` и срез сообщений — ответственность вызывающего кода;
 * - сервис старается быть “безопасным по умолчанию”: если данных недостаточно, summary пустой, или параметры некорректны —
 *   он делает early-return и **не трогает историю**.
 *
 * Почему фильтры нужны:
 * - tool-call/tool-result и особенно `chat_history.*` могут раздувать transcript огромными JSON/дампами;
 * - короткие “OK/YES/…” и подряд повторяющиеся строки часто дают низкосигнальный summary и расходуют токены;
 * - фильтры помогают стабилизировать стоимость и качество.
 *
 * Пример использования:
 *
 * <code>
 * $svc = new SummarizeService(
 *     useSkill: true,
 *     skill: $skill,
 *     mode: 'replace_range',
 *     role: MessageRole::ASSISTANT,
 *     minTranscriptChars: 50,
 *     debug: false,
 *     logger: $logger
 * );
 *
 * $svc->summarizeAndApply(
 *     agentCfg: $agentCfg,
 *     history: $agentCfg->getChatHistory(),
 *     countBefore: $before,
 *     countAfter: $after,
 *     stepMessages: $deltaMessages,
 *     contextName: 'step'
 * );
 * </code>
 */
final class SummarizeService
{
    /**
     * Конструктор задаёт стратегию суммаризации и её “политику” (фильтры/режимы/логирование).
     *
     * Параметры намеренно “плоские”: сервис можно создать один раз на цикл, не привязываясь к источнику конфигурации.
     *
     * @param bool $useSkill
     *  Если true — summary вычисляется через {@see Skill} (LLM-вызов).
     *  Если false — summary = сформированный `transcript` (после фильтров), т.е. без LLM.
     *
     * @param Skill|null $skill
     *  Экземпляр skill суммаризации. Используется только при `$useSkill === true`.
     *  Если `$useSkill === true`, но `$skill === null`, сервис ничего не применит (early-return).
     *
     * @param string $mode
     *  Режим применения summary к истории:
     *  - `replace_range`: заменить сообщения шага одним сообщением summary (предпочтительно для “сжатия” истории).
     *  - `append_summary`: оставить оригинальные сообщения и дописать summary отдельным сообщением (удобно для аудита).
     *
     * @param MessageRole $role
     *  Роль summary-сообщения. Обычно `assistant`, иногда удобно `system`, чтобы модель воспринимала это как контекст.
     *
     * @param int $minTranscriptChars
     *  Минимальная длина transcript (после trim) для запуска суммаризации.
     *  Защита от “summary из ничего”. 0 отключает проверку.
     *
     * @param bool $debug
     *  Включить/выключить debug-логирование skip/apply. Логи пишутся только если задан `$logger`.
     *
     * @param LoggerInterface|null $logger
     *  PSR-3 логгер. Если null — логирование отключено даже при `$debug=true`.
     *
     * @param bool $filterToolMessages
     *  Если true — tool-call/tool-result сообщения исключаются из transcript.
     *  Обычно это полезно, т.к. tool-result может быть большим и слабо связанным с итогом.
     *
     * @param bool $filterHistoryTools
     *  Если true — дополнительно исключаются tool-сообщения инструментов просмотра истории (`chat_history.*`),
     *  чтобы не тащить в summary большие дампы.
     *
     * @param int $minMessageChars
     *  Минимальная длина отдельного сообщения (после trim), чтобы оно попало в transcript.
     *  Позволяет выкидывать “OK”, “.” и т.п.
     *
     * @param bool $dedupConsecutive
     *  Если true — подряд повторяющиеся сообщения с одинаковыми role+content удаляются.
     *  Важно: дедупликация **только подряд**, чтобы не “ломать” смысл диалога.
     *
     * @param list<string> $historyToolNames
     *  Список имён history-tools, которые считаются шумом (используется только при `$filterHistoryTools=true`).
     */
    public function __construct(
        private readonly bool $useSkill = false,
        private readonly ?Skill $skill = null,
        private readonly string $mode = 'replace_range',
        private readonly MessageRole $role = MessageRole::ASSISTANT,
        private readonly int $minTranscriptChars = 50,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $filterToolMessages = true,
        private readonly bool $filterHistoryTools = true,
        private readonly int $minMessageChars = 3,
        private readonly bool $dedupConsecutive = true,
        private readonly bool $dedupTranscriptGlobal = true,
        private readonly bool $excludeLlmCycleHelperPrompts = true,
        private readonly array $historyToolNames = ['chat_history.size', 'chat_history.meta', 'chat_history.message'],
    ) {
    }

    /**
     * Суммаризирует сообщения шага и применяет результат к истории.
     *
     * Входные условия:
     * - `$countBefore/$countAfter` — два последовательных измерения размера *той же* истории (`$history`),
     *   сделанные вызывающим кодом до/после шага.
     * - `$stepMessages` — массив сообщений, который должен соответствовать диапазону `[before..after)`.
     *   Сервис **не проверяет** соответствие по ссылкам/идентификаторам, он работает по доверенному контракту.
     *
     * Что делает метод:
     * 1) Проверяет, что дельта положительная.
     * 2) Пропускает входные сообщения через фильтры шума.
     * 3) Строит transcript в стабильном виде:
     *    `[role]\ncontent` (каждое сообщение отделено пустой строкой).
     * 4) Если transcript слишком короткий — ничего не делает (защита).
     * 5) Получает summary:
     *    - при `useSkill=false`: summary=transcript;
     *    - при `useSkill=true`: вызывает skill с параметром `transcript`.
     * 6) Применяет summary к истории согласно mode:
     *    - `replace_range`: заменяет исходный диапазон `[before..after)` на одно сообщение;
     *    - `append_summary`: вставляет/добавляет summary как новое сообщение после шага.
     *
     * Поведение при ошибках/нехватке данных:
     * - Если `$useSkill=true`, но `$skill=null` — метод завершится без изменения истории.
     * - Если summary пустой — изменения не применяются.
     *
     * @param ConfigurationAgent $agentCfg
     *  Конфигурация агента. Нужна для вызова Skill: `Skill::setDefaultConfigurationAgent($agentCfg)`.
     *  Также позволяет сохранить единый контекст/логгер на стороне вызывающего кода.
     *
     * @param ChatHistoryInterface $history
     *  История, к которой применяется результат. Для `append_summary` важна поддержка вставки:
     *  - {@see AbstractFullChatHistory}: вставка делается по индексу `countAfter` в полную историю;
     *  - иначе: fallback на `$history->addMessage(...)`.
     *
     * @param int $countBefore Размер истории до шага.
     * @param int $countAfter Размер истории после шага.
     * @param array<int, \NeuronAI\Chat\Messages\Message> $stepMessages Сообщения шага (дельта).
     * @param string $contextName Произвольная метка для debug-логов (например, имя todolist).
     */
    public function summarizeAndApply(
        ConfigurationAgent $agentCfg,
        ChatHistoryInterface $history,
        int $countBefore,
        int $countAfter,
        array $stepMessages,
        string $contextName = ''
    ): void {
        $delta = $countAfter - $countBefore;
        if ($delta <= 0) {
            return;
        }

        $filtered = $this->filterMessages($stepMessages);
        if ($filtered === []) {
            $this->debugLog('Step summarization skipped: empty_after_filter', [
                'context'   => $contextName,
                'before'    => $countBefore,
                'after'     => $countAfter,
                'delta'     => $delta,
                'rawCount'  => count($stepMessages),
                'keptCount' => 0,
                'useSkill'  => $this->useSkill,
                'mode'      => $this->normalizeMode($this->mode),
                'role'      => $this->role->value,
                'hasSkill'  => $this->skill instanceof Skill,
            ]);
            return;
        }

        $transcript = $this->renderTranscript($filtered);
        $minChars = max(0, $this->minTranscriptChars);
        $transcriptChars = mb_strlen(trim($transcript));
        if ($minChars > 0 && $transcriptChars < $minChars) {
            $this->debugLog('Step summarization skipped: transcript_too_short', [
                'context'         => $contextName,
                'before'          => $countBefore,
                'after'           => $countAfter,
                'delta'           => $delta,
                'rawCount'        => count($stepMessages),
                'keptCount'       => count($filtered),
                'transcriptChars' => $transcriptChars,
                'minChars'        => $minChars,
                'useSkill'        => $this->useSkill,
                'mode'            => $this->normalizeMode($this->mode),
                'role'            => $this->role->value,
                'hasSkill'        => $this->skill instanceof Skill,
            ]);
            return;
        }

        $summary = $transcript;
        if ($this->useSkill) {
            if (!$this->skill instanceof Skill) {
                return;
            }

            $this->skill->setDefaultConfigurationAgent($agentCfg);
            $summaryRaw = $this->skill->execute(MessageRole::USER, [], ['transcript' => $transcript])->await();
            $summary = $this->normalizeSummaryToString($summaryRaw);
        }

        $summary = trim($summary);
        if ($summary === '') {
            return;
        }

        $summaryMessage = new NeuronMessage($this->role, $summary);
        $mode = $this->normalizeMode($this->mode);

        if ($mode === 'append_summary') {
            if ($history instanceof AbstractFullChatHistory) {
                ChatHistoryEditHelper::insertFullMessageAt($history, $countAfter, $summaryMessage);
            } else {
                $history->addMessage($summaryMessage);
            }
        } else {
            ChatHistoryEditHelper::replaceMessagesBySnapshotRange($history, $countBefore, $countAfter, $summaryMessage);
        }

        $this->debugLog('Step summarization applied', [
            'context'         => $contextName,
            'before'          => $countBefore,
            'after'           => $countAfter,
            'delta'           => $delta,
            'rawCount'        => count($stepMessages),
            'keptCount'       => count($filtered),
            'transcriptChars' => $transcriptChars,
            'summaryChars'    => mb_strlen($summary),
            'useSkill'        => $this->useSkill,
            'mode'            => $mode,
            'role'            => $this->role->value,
            'hasSkill'        => $this->skill instanceof Skill,
        ]);
    }

    /**
     * Фильтрует входные сообщения шага, чтобы сделать transcript компактным и полезным.
     *
     * Применяемые правила (если включены):
     * - убрать tool-call/tool-result;
     * - убрать history-tools (`chat_history.*`) как частный случай tool-сообщений;
     * - убрать пустые и “слишком короткие” сообщения;
     * - убрать подряд повторяющиеся сообщения.
     *
     * @param array<int, \NeuronAI\Chat\Messages\Message> $messages
     * @return array<int, \NeuronAI\Chat\Messages\Message>
     */
    private function filterMessages(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $out = [];
        $prevKey = null;
        $minChars = max(0, $this->minMessageChars);

        foreach ($messages as $m) {
            if ($this->filterToolMessages && ($m instanceof ToolCallMessage || $m instanceof ToolResultMessage)) {
                if ($this->filterHistoryTools && ChatHistoryToolMessageHelper::isToolMessageInList($m, $this->historyToolNames)) {
                    continue;
                }

                continue;
            }

            $content = (string) ($m->getContent() ?? '');
            $trimmed = trim($content);
            if ($trimmed === '') {
                continue;
            }
            if ($minChars > 0 && mb_strlen($trimmed) < $minChars) {
                continue;
            }

            $key = (string) $m->getRole() . '|' . $trimmed;
            if ($this->dedupConsecutive && $prevKey === $key) {
                continue;
            }
            $prevKey = $key;

            $out[] = $m;
        }

        return $out;
    }

    /**
     * Строит детерминированный transcript из сообщений.
     *
     * Формат:
     *
     * <code>
     * [assistant]
     * hello
     *
     * [user]
     * do X
     * </code>
     *
     * Почему так:
     * - удобно читать человеку (в логах/debug);
     * - стабильно для prompt-инжиниринга и тестов;
     * - не завязано на внутренние детали Message-классов.
     *
     * @param array<int, \NeuronAI\Chat\Messages\Message> $messages
     */
    private function renderTranscript(array $messages): string
    {
        $lines = [];
        $seen = [];
        foreach ($messages as $m) {
            $role = (string) $m->getRole();
            $content = (string) ($m->getContent() ?? '');
            $trimmed = trim($content);
            if ($trimmed === '') {
                continue;
            }

            if ($this->excludeLlmCycleHelperPrompts) {
                if (
                    $trimmed === LlmCycleHelper::MSG_CHECK_WORK
                    || $trimmed === LlmCycleHelper::MSG_CHECK_WORK2
                    || $trimmed === LlmCycleHelper::MSG_RESULT
                ) {
                    continue;
                }
            }

            if ($this->dedupTranscriptGlobal) {
                // Дедуплицируем именно контент, независимо от роли:
                // LLM может повторять один и тот же текст как user/assistant, либо повторять результат инструмента.
                $key = strtolower($trimmed);
                if (array_key_exists($key, $seen)) {
                    continue;
                }
                $seen[$key] = true;
            }

            $lines[] = sprintf("[%s]\n%s", $role, $trimmed);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Нормализует строковый режим применения summary к истории.
     *
     * Принимает произвольную строку (например, из конфигурации), приводит к нижнему регистру,
     * обрезает пробелы и проверяет, что режим входит в whitelist:
     * - `replace_range`
     * - `append_summary`
     *
     * Если значение неизвестно или пустое — возвращает безопасный дефолт `replace_range`.
     *
     * @param string $mode Значение режима из внешней конфигурации.
     * @return 'replace_range'|'append_summary'
     */
    private function normalizeMode(string $mode): string
    {
        $m = strtolower(trim($mode));
        return in_array($m, ['replace_range', 'append_summary'], true) ? $m : 'replace_range';
    }

    /**
     * Пишет debug-лог, если включён debug и передан logger.
     *
     * Сервис намеренно не бросает исключения из-за отсутствия логгера:
     * логирование — вторично и не должно ломать основной алгоритм.
     *
     * @param string $message Сообщение лога (короткая метка события).
     * @param array<string, mixed> $context Контекст: размеры, режимы, метрики, flags.
     */
    private function debugLog(string $message, array $context): void
    {
        if (!$this->debug || $this->logger === null) {
            return;
        }

        $this->logger->info($message, $context);
    }

    /**
     * Приводит “сырое” значение из Skill к строке.
     *
     * Skill может вернуть:
     * - {@see NeuronMessage} (обычный чат-ответ),
     * - строку,
     * - JSON-serializable объект/DTO,
     * - массив.
     *
     * Важно: сервис не пытается “угадывать” структуру DTO — он сериализует в JSON,
     * чтобы сохранить информацию, если ответ не является строкой.
     */
    private function normalizeSummaryToString(mixed $summaryRaw): string
    {
        if ($summaryRaw instanceof NeuronMessage) {
            return (string) ($summaryRaw->getContent() ?? '');
        }
        if (is_string($summaryRaw)) {
            return $summaryRaw;
        }
        if ($summaryRaw instanceof \JsonSerializable) {
            $encoded = json_encode($summaryRaw->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }
        if (is_array($summaryRaw)) {
            $encoded = json_encode($summaryRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }

        return (string) $summaryRaw;
    }
}
