<?php

declare(strict_types=1);

namespace Tests\Storage;

use app\modules\neuron\classes\dto\mind\MindRecordDto;
use app\modules\neuron\classes\storage\UserMindMarkdownStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты {@see UserMindMarkdownStorage}: формат блоков, индекс, удаление, замена, оценка среза.
 */
class UserMindMarkdownStorageTest extends TestCase
{
    private string $tmpMind;

    protected function setUp(): void
    {
        $this->tmpMind = sys_get_temp_dir() . '/neuronapp_mind_' . uniqid();
        mkdir($this->tmpMind, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpMind);
    }

    /**
     * Первый append создаёт файлы и возвращает recordId = 1.
     */
    public function testAppendCreatesFirstRecordWithIdOne(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 100);
        $id = $s->appendMessage('sk-1', 'user', 'Hello', null);
        $this->assertSame(1, $id);
        $row = $s->getByRecordId(1);
        $this->assertNotNull($row);
        $this->assertSame('Hello', $row->getBody());
        $this->assertSame('user', $row->getRole());
    }

    /**
     * Второй append увеличивает recordId и сохраняет разделитель `\n\n\n\n` между блоками в файле.
     */
    public function testSecondAppendIncrementsIdAndUsesBlockSeparator(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 2);
        $s->appendMessage('sk', 'user', 'A', null);
        $id2 = $s->appendMessage('sk', 'assistant', 'B', null);
        $this->assertSame(2, $id2);
        $raw = (string) file_get_contents($this->tmpMind . '/user_2.md');
        $this->assertStringContainsString("\n\n\n\n", $raw);
    }

    /**
     * getByRecordId для несуществующего id возвращает null (граница поиска).
     */
    public function testGetByRecordIdReturnsNullForUnknownId(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 3);
        $this->assertNull($s->getByRecordId(99));
    }

    /**
     * Тройные переводы строк в теле схлопываются до двойного (без «двойной пустой» внутри тела).
     */
    public function testBodyNormalizesTripleNewlines(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 4);
        $s->appendMessage('sk', 'user', "x\n\n\n\ny", null);
        $row = $s->getByRecordId(1);
        $this->assertNotNull($row);
        $this->assertStringNotContainsString("\n\n\n\n", $row->getBody());
    }

    /**
     * UTF-8 символы сохраняются; оценка символов использует многобайтовую длину.
     */
    public function testUtf8RoundTripAndCharacterCount(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 5);
        $text = '€Язык';
        $s->appendMessage('sk', 'user', $text, null);
        $row = $s->getByRecordId(1);
        $this->assertNotNull($row);
        $this->assertSame($text, $row->getBody());
        $est = $s->estimateSlice([1]);
        $this->assertGreaterThanOrEqual(mb_strlen($text, 'UTF-8'), $est->getCharacterCount());
    }

    /**
     * deleteByRecordIds удаляет выбранные записи, остальные читаются по id.
     */
    public function testDeleteRemovesSelectedRecords(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 6);
        $s->appendMessage('sk', 'user', 'a', null);
        $s->appendMessage('sk', 'user', 'b', null);
        $s->appendMessage('sk', 'user', 'c', null);
        $s->deleteByRecordIds([2]);
        $this->assertNotNull($s->getByRecordId(1));
        $this->assertNull($s->getByRecordId(2));
        $this->assertNotNull($s->getByRecordId(3));
    }

    /**
     * deleteByRecordIds с пустым массивом — no-op (граничный случай).
     */
    public function testDeleteWithEmptyArrayIsNoOp(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 7);
        $s->appendMessage('sk', 'user', 'only', null);
        $s->deleteByRecordIds([]);
        $this->assertNotNull($s->getByRecordId(1));
    }

    /**
     * replaceByRecordIds заменяет тело и метаданные существующей записи.
     */
    public function testReplaceUpdatesRecord(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 8);
        $s->appendMessage('sk', 'user', 'old', null);
        $dto = (new MindRecordDto())
            ->setRecordId(1)
            ->setCapturedAt('2026-04-12T10:00:00+00:00')
            ->setSessionKey('sk2')
            ->setRole('assistant')
            ->setBody('new');
        $s->replaceByRecordIds([$dto]);
        $row = $s->getByRecordId(1);
        $this->assertNotNull($row);
        $this->assertSame('new', $row->getBody());
        $this->assertSame('assistant', $row->getRole());
    }

    /**
     * replaceByRecordIds для несуществующего recordId выбрасывает исключение (неверные данные).
     */
    public function testReplaceThrowsWhenRecordMissing(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 81);
        $s->appendMessage('sk', 'user', 'x', null);
        $this->expectException(RuntimeException::class);
        $s->replaceByRecordIds([
            (new MindRecordDto())
                ->setRecordId(999)
                ->setCapturedAt('2026-04-12T10:00:00+00:00')
                ->setSessionKey('x')
                ->setRole('user')
                ->setBody('nope'),
        ]);
    }

    /**
     * estimateSlice игнорирует несуществующие id и не падает (некорректные данные).
     */
    public function testEstimateSliceIgnoresUnknownIds(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 9);
        $s->appendMessage('sk', 'user', 'z', null);
        $est = $s->estimateSlice([1, 404, 1]);
        $this->assertGreaterThan(0, $est->getCharacterCount());
        $this->assertGreaterThanOrEqual(0, $est->getTokenCount());
    }

    /**
     * Срез id в произвольном порядке даёт сумму по существующим записям (дубликаты id не удваивают).
     */
    public function testEstimateSliceWithUnsortedIds(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 10);
        $s->appendMessage('sk', 'user', 'aa', null);
        $s->appendMessage('sk', 'user', 'bb', null);
        $e1 = $s->estimateSlice([1, 2]);
        $e2 = $s->estimateSlice([2, 1, 1]);
        $this->assertSame($e1->getCharacterCount(), $e2->getCharacterCount());
    }

    /**
     * Строковый userId даёт предсказуемое имя файла через хелпер (безопасные символы).
     */
    public function testStringUserIdUsesSafeBasename(): void
    {
        $s = new UserMindMarkdownStorage($this->tmpMind, 'foo/bar');
        $s->appendMessage('sk', 'user', 'x', null);
        $this->assertFileExists($this->tmpMind . '/user_foo_bar.md');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            $f->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
