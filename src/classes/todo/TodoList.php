<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\todo;

use Amp\Future;
use app\modules\neuron\classes\APromptComponent;
use app\modules\neuron\ConfigurationAgent;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\interfaces\ITodo;
use app\modules\neuron\interfaces\ITodoList;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * Список заданий Todo, формируемый из текстового ввода.
 *
 * Использует общий парсер APromptComponent для разбора опций и тела,
 * а затем строит очередь заданий из текстового блока.
 * Задания хранятся в виде очереди и извлекаются по принципу FIFO.
 */
class TodoList extends APromptComponent implements ITodoList
{
    /**
     * Очередь заданий в порядке FIFO.
     *
     * @var ITodo[]
     */
    private array $todos = [];

    /**
     * Создает список заданий на основе входного текста.
     *
     * Текст может содержать:
     *  - только блок заданий;
     *  - блок опций и блок заданий, разделенные линиями из '-';
     *  - только блок опций (без заданий);
     *  - быть пустым (без опций и заданий).
     *
     * @param string $input Полный текст описания списка.
     */
    public function __construct(string $input)
    {
        parent::__construct($input);
        $this->body = CommentsHelper::stripComments($this->body);
        $this->initTodos();
    }

    /**
     * Добавляет одно или несколько заданий в очередь.
     *
     * @param ITodo ...$todos Экземпляры заданий для добавления.
     */
    public function pushTodo(ITodo ...$todos): void
    {
        foreach ($todos as $todo) {
            $this->todos[] = $todo;
        }
    }

    /**
     * Извлекает одно задание из начала списка по принципу FIFO.
     *
     * @return ITodo|null Задание либо null, если список пуст.
     */
    public function popTodo(): ?ITodo
    {
        if ($this->todos === []) {
            return null;
        }

        /** @var ITodo $todo */
        $todo = array_shift($this->todos);

        return $todo;
    }

    /**
     * Возвращает массив заданий (копию списка) для итерации без изменения очереди.
     *
     * @return list<ITodo>
     */
    public function getTodos(): array
    {
        return array_values($this->todos);
    }

    /**
     * @inheritDoc
     *
     * @return Future<list<NeuronMessage>> Завершается копией истории сообщений агента после выполнения всех заданий.
     */
    public function executeFromAgent(ConfigurationAgent $agentCfg, MessageRole $role = MessageRole::USER): Future
    {
        return \Amp\async(function () use ($agentCfg, $role): ChatHistoryInterface {
            foreach ($this->getTodos() as $todo) {
                $message = new NeuronMessage($role, $todo->getTodo());
                $agentCfg->sendMessage($message);
            }
            return clone $agentCfg->getChatHistory();
        });
    }

    /**
     * Инициализирует список заданий на основе текстового тела.
     *
     * Поддерживаются как нумерованные задания («1. Текст»), так и
     * единственное ненумерованное задание, если номеров нет вовсе.
     * Пустые строки в начале блока заданий пропускаются.
     */
    private function initTodos(): void
    {
        $body = $this->getBody();
        if ($body === '') {
            return;
        }

        $lines = explode("\n", $body);

        // Пропускаем начальные пустые строки
        while ($lines !== [] && trim(reset($lines)) === '') {
            array_shift($lines);
        }

        $hasNumbered = false;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\s+/', $line) === 1) {
                $hasNumbered = true;
                break;
            }
        }

        if (!$hasNumbered) {
            $text = trim(implode("\n", $lines));
            if ($text !== '') {
                $this->pushTodo(Todo::fromString($text));
            }

            return;
        }

        $currentLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\.\s+(.*)$/', $line, $matches) === 1) {
                if ($currentLines !== []) {
                    $this->finalizeTodo($currentLines);
                    $currentLines = [];
                }

                $currentLines[] = $matches[2];
            } else {
                $currentLines[] = $line;
            }
        }

        if ($currentLines !== []) {
            $this->finalizeTodo($currentLines);
        }
    }

    /**
     * Завершает формирование одного задания и добавляет его в список.
     *
     * @param string[] $lines Строки текста одного задания.
     */
    private function finalizeTodo(array $lines): void
    {
        $text = rtrim(implode("\n", $lines), "\n");

        if ($text === '') {
            return;
        }

        $this->pushTodo(Todo::fromString($text));
    }

}

