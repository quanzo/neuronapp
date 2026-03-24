<?php

declare(strict_types=1);

namespace Tests\Events;

use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see EventBus}.
 *
 * Проверяется поведение контекста подписок:
 * - object-подписки (конкретный экземпляр),
 * - class-подписки (точный класс),
 * - fallback на глобальные обработчики (`*`),
 * - порядок вызова обработчиков для объектного trigger.
 */
class EventBusTest extends TestCase
{
    /**
     * Явно подключаем тестируемый класс, так как он расположен вне PSR-4 пути автозагрузки.
     */
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\app\modules\neuron\classes\events\EventBus::class)) {
            require_once __DIR__ . '/../../src/classes/events/EventBus.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        \app\modules\neuron\classes\events\EventBus::clear();
    }

    protected function tearDown(): void
    {
        \app\modules\neuron\classes\events\EventBus::clear();
        parent::tearDown();
    }

    /**
     * Подписка на объект срабатывает только для того же экземпляра.
     */
    public function testOnWithObjectBindsOnlyToExactObject(): void
    {
        $objectA = new \stdClass();
        $objectB = new \stdClass();
        $calls = 0;

        \app\modules\neuron\classes\events\EventBus::on('event.object', static function () use (&$calls): void {
            $calls++;
        }, $objectA);

        \app\modules\neuron\classes\events\EventBus::trigger('event.object', $objectB, null);
        \app\modules\neuron\classes\events\EventBus::trigger('event.object', $objectA, null);

        $this->assertSame(1, $calls);
    }

    /**
     * trigger(object) вызывает object -> class -> parent -> global в заданном порядке.
     */
    public function testTriggerObjectUsesExpectedHandlerOrder(): void
    {
        $subject = new class () extends \stdClass {
        };
        $order = [];
        $parentClass = get_parent_class($subject);
        $childClass = get_class($subject);

        \app\modules\neuron\classes\events\EventBus::on('event.order', static function () use (&$order): void {
            $order[] = 'global';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('event.order', static function () use (&$order): void {
            $order[] = 'parent';
        }, $parentClass);
        \app\modules\neuron\classes\events\EventBus::on('event.order', static function () use (&$order): void {
            $order[] = 'child';
        }, $childClass);
        \app\modules\neuron\classes\events\EventBus::on('event.order', static function () use (&$order): void {
            $order[] = 'object';
        }, $subject);

        \app\modules\neuron\classes\events\EventBus::trigger('event.order', $subject, null);

        $this->assertSame(['object', 'child', 'parent', 'global'], $order);
    }

    /**
     * trigger(class-string) вызывает точный class-обработчик и затем global fallback.
     */
    public function testTriggerClassUsesClassAndGlobalFallback(): void
    {
        $subjectClass = get_class(new class () extends \stdClass {
        });
        $order = [];

        \app\modules\neuron\classes\events\EventBus::on('event.class', static function () use (&$order): void {
            $order[] = 'global';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('event.class', static function () use (&$order): void {
            $order[] = 'child';
        }, $subjectClass);

        \app\modules\neuron\classes\events\EventBus::trigger('event.class', $subjectClass, null);

        $this->assertSame(['child', 'global'], $order);
    }

    /**
     * trigger(class-string) не вызывает parent-обработчики (только точный класс + global).
     */
    public function testTriggerClassDoesNotInvokeParentClassHandlers(): void
    {
        $subject = new class () extends \stdClass {
        };
        $calls = [];
        $parentClass = get_parent_class($subject);
        $childClass = get_class($subject);

        \app\modules\neuron\classes\events\EventBus::on('event.class.parent', static function () use (&$calls): void {
            $calls[] = 'parent';
        }, $parentClass);
        \app\modules\neuron\classes\events\EventBus::on('event.class.parent', static function () use (&$calls): void {
            $calls[] = 'global';
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('event.class.parent', $childClass, null);

        $this->assertSame(['global'], $calls);
    }

    /**
     * trigger(class-string) не должен вызывать object-подписки.
     */
    public function testTriggerClassDoesNotInvokeObjectHandlers(): void
    {
        $object = new class () extends \stdClass {
        };
        $calls = 0;

        \app\modules\neuron\classes\events\EventBus::on('event.no-object', static function () use (&$calls): void {
            $calls++;
        }, $object);

        \app\modules\neuron\classes\events\EventBus::trigger('event.no-object', get_class($object), null);

        $this->assertSame(0, $calls);
    }

    /**
     * off(object) снимает только обработчик конкретного объекта.
     */
    public function testOffWithObjectRemovesOnlyExactObjectHandler(): void
    {
        $objectA = new \stdClass();
        $objectB = new \stdClass();
        $calls = 0;

        $handler = static function () use (&$calls): void {
            $calls++;
        };

        \app\modules\neuron\classes\events\EventBus::on('event.off.object', $handler, $objectA);
        \app\modules\neuron\classes\events\EventBus::on('event.off.object', $handler, $objectB);
        \app\modules\neuron\classes\events\EventBus::off('event.off.object', $handler, $objectA);

        \app\modules\neuron\classes\events\EventBus::trigger('event.off.object', $objectA, null);
        \app\modules\neuron\classes\events\EventBus::trigger('event.off.object', $objectB, null);

        $this->assertSame(1, $calls);
    }

    /**
     * off(class-string) снимает обработчик точного класса.
     */
    public function testOffWithClassRemovesOnlyClassHandler(): void
    {
        $subjectClass = get_class(new class () extends \stdClass {
        });
        $calls = [];
        $handler = static function () use (&$calls): void {
            $calls[] = 'class';
        };

        \app\modules\neuron\classes\events\EventBus::on('event.off.class', $handler, $subjectClass);
        \app\modules\neuron\classes\events\EventBus::on('event.off.class', static function () use (&$calls): void {
            $calls[] = 'global';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::off('event.off.class', $handler, $subjectClass);

        \app\modules\neuron\classes\events\EventBus::trigger('event.off.class', $subjectClass, null);

        $this->assertSame(['global'], $calls);
    }

    /**
     * Повторная подписка того же обработчика на объект не дублируется.
     */
    public function testDuplicateObjectHandlerIsNotAddedTwice(): void
    {
        $subject = new \stdClass();
        $calls = 0;
        $handler = static function () use (&$calls): void {
            $calls++;
        };

        \app\modules\neuron\classes\events\EventBus::on('event.dup.object', $handler, $subject);
        \app\modules\neuron\classes\events\EventBus::on('event.dup.object', $handler, $subject);

        \app\modules\neuron\classes\events\EventBus::trigger('event.dup.object', $subject, null);

        $this->assertSame(1, $calls);
    }

    /**
     * Повторная подписка того же обработчика на класс не дублируется.
     */
    public function testDuplicateClassHandlerIsNotAddedTwice(): void
    {
        $subjectClass = get_class(new class () extends \stdClass {
        });
        $calls = 0;
        $handler = static function () use (&$calls): void {
            $calls++;
        };

        \app\modules\neuron\classes\events\EventBus::on('event.dup.class', $handler, $subjectClass);
        \app\modules\neuron\classes\events\EventBus::on('event.dup.class', $handler, $subjectClass);

        \app\modules\neuron\classes\events\EventBus::trigger('event.dup.class', $subjectClass, null);

        $this->assertSame(1, $calls);
    }

    /**
     * trigger('*') вызывает только глобальные обработчики.
     */
    public function testTriggerGlobalInvokesOnlyGlobalHandlers(): void
    {
        $subjectClass = get_class(new class () extends \stdClass {
        });
        $calls = [];

        \app\modules\neuron\classes\events\EventBus::on('event.global', static function () use (&$calls): void {
            $calls[] = 'global';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('event.global', static function () use (&$calls): void {
            $calls[] = 'class';
        }, $subjectClass);

        \app\modules\neuron\classes\events\EventBus::trigger('event.global', '*', null);

        $this->assertSame(['global'], $calls);
    }

    /**
     * trigger дочернего имени вызывает обработчики в порядке:
     * event.child -> event -> *.
     */
    public function testTriggerEventNameHierarchyUsesSpecificParentAndWildcardOrder(): void
    {
        $calls = [];

        \app\modules\neuron\classes\events\EventBus::on('skill.completed', static function () use (&$calls): void {
            $calls[] = 'skill.completed';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('skill', static function () use (&$calls): void {
            $calls[] = 'skill';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('*', static function () use (&$calls): void {
            $calls[] = '*';
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('skill.completed', '*', null);

        $this->assertSame(['skill.completed', 'skill', '*'], $calls);
    }

    /**
     * Обработчик на "родительском" событии ловит дочерние события.
     */
    public function testParentEventHandlerCatchesChildEvent(): void
    {
        $calls = 0;

        \app\modules\neuron\classes\events\EventBus::on('skill', static function () use (&$calls): void {
            $calls++;
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('skill.failed', '*', null);
        \app\modules\neuron\classes\events\EventBus::trigger('skill.completed', '*', null);

        $this->assertSame(2, $calls);
    }

    /**
     * Возврат false останавливает всю цепочку иерархии имен событий.
     */
    public function testFalseReturnStopsEventNameHierarchyChain(): void
    {
        $calls = [];

        \app\modules\neuron\classes\events\EventBus::on('skill.completed', static function () use (&$calls): bool {
            $calls[] = 'skill.completed';
            return false;
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('skill', static function () use (&$calls): void {
            $calls[] = 'skill';
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('*', static function () use (&$calls): void {
            $calls[] = '*';
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('skill.completed', '*', null);

        $this->assertSame(['skill.completed'], $calls);
    }

    /**
     * Callback может принять вторым аргументом инициатор события.
     */
    public function testTriggerPassesInitiatorAsSecondCallbackArgument(): void
    {
        $subject = new \stdClass();
        $receivedInitiator = null;

        \app\modules\neuron\classes\events\EventBus::on('event.initiator', static function (mixed $payload, object|string $initiator) use (&$receivedInitiator): void {
            $receivedInitiator = $initiator;
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('event.initiator', $subject, ['ok' => true]);

        $this->assertSame($subject, $receivedInitiator);
    }

    /**
     * Callback без аргумента инициатора остаётся рабочим.
     */
    public function testTriggerDoesNotBreakCallbackWithoutInitiatorArgument(): void
    {
        $calls = 0;

        \app\modules\neuron\classes\events\EventBus::on('event.no-initiator', static function (): void {
            // Проверяем, что дополнительный аргумент не ломает вызов обработчика.
        }, '*');
        \app\modules\neuron\classes\events\EventBus::on('event.no-initiator', static function () use (&$calls): void {
            $calls++;
        }, '*');

        \app\modules\neuron\classes\events\EventBus::trigger('event.no-initiator', '*', null);

        $this->assertSame(1, $calls);
    }
}
