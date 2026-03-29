<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\JsonHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты единой обёртки JSON для проекта {@see JsonHelper}.
 */
final class JsonHelperTest extends TestCase
{
    /**
     * encode: кириллица не экранируется в \\uXXXX при флаге Unicode.
     */
    public function testEncodePreservesUnicode(): void
    {
        $json = JsonHelper::encode(['текст' => 'привет']);
        $this->assertIsString($json);
        $this->assertStringContainsString('привет', $json);
        $this->assertStringNotContainsString('\u', $json);
    }

    /**
     * encodeThrow: бросает при несериализуемом значении (например INF).
     */
    public function testEncodeThrowThrowsOnInvalidValue(): void
    {
        $this->expectException(\JsonException::class);
        JsonHelper::encodeThrow(INF);
    }

    /**
     * decodeAssociative: валидный объект — массив.
     */
    public function testDecodeAssociativeValidObject(): void
    {
        $this->assertSame(['a' => 1], JsonHelper::decodeAssociative('{"a":1}'));
    }

    /**
     * decodeAssociative: синтаксическая ошибка — null.
     */
    public function testDecodeAssociativeInvalidReturnsNull(): void
    {
        $this->assertNull(JsonHelper::decodeAssociative('{'));
    }

    /**
     * decodeAssociativeOrEmpty: битый JSON даёт [] как у json_decode ?? [].
     */
    public function testDecodeAssociativeOrEmptyOnInvalidReturnsEmptyArray(): void
    {
        $this->assertSame([], JsonHelper::decodeAssociativeOrEmpty('not json'));
    }

    /**
     * decodeAssociativeOrEmpty: литерал null в JSON даёт [] из-за ??.
     */
    public function testDecodeAssociativeOrEmptyJsonNullBecomesEmptyArray(): void
    {
        $this->assertSame([], JsonHelper::decodeAssociativeOrEmpty('null'));
    }

    /**
     * tryDecodeAssociativeArray: валидный объект — массив.
     */
    public function testTryDecodeAssociativeArraySuccess(): void
    {
        $this->assertSame(['x' => true], JsonHelper::tryDecodeAssociativeArray('{"x":true}'));
    }

    /**
     * tryDecodeAssociativeArray: невалидный JSON — null.
     */
    public function testTryDecodeAssociativeArrayInvalidJsonReturnsNull(): void
    {
        $this->assertNull(JsonHelper::tryDecodeAssociativeArray('{'));
    }

    /**
     * tryDecodeAssociativeArray: корень-скаляр — null (не array).
     */
    public function testTryDecodeAssociativeArrayScalarRootReturnsNull(): void
    {
        $this->assertNull(JsonHelper::tryDecodeAssociativeArray('"only"'));
    }

    /**
     * decodeAssociativeThrow: успешный разбор.
     */
    public function testDecodeAssociativeThrowSuccess(): void
    {
        $this->assertSame(['k' => 2], JsonHelper::decodeAssociativeThrow('{"k":2}'));
    }

    /**
     * decodeAssociativeThrow: ошибка синтаксиса.
     */
    public function testDecodeAssociativeThrowOnInvalid(): void
    {
        $this->expectException(\JsonException::class);
        JsonHelper::decodeAssociativeThrow(':::');
    }

    /**
     * decodeAssociativeForConfigFile: успех.
     */
    public function testDecodeAssociativeForConfigFileSuccess(): void
    {
        $arr = JsonHelper::decodeAssociativeForConfigFile('{"a":1}', '/tmp/x.jsonc');
        $this->assertSame(['a' => 1], $arr);
    }

    /**
     * decodeAssociativeForConfigFile: невалидный JSON — RuntimeException с путём.
     */
    public function testDecodeAssociativeForConfigFileInvalidJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('/path/cfg.jsonc');
        JsonHelper::decodeAssociativeForConfigFile('{', '/path/cfg.jsonc');
    }

    /**
     * decodeAssociativeForConfigFile: корень не массив — отдельное сообщение.
     */
    public function testDecodeAssociativeForConfigFileNotArrayThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must decode to an associative array');
        JsonHelper::decodeAssociativeForConfigFile('42', '/y.jsonc');
    }

    /**
     * encodeUnicodeWithUtf8Fallback: обычные данные — строгий JSON.
     */
    public function testEncodeUnicodeWithUtf8FallbackNormal(): void
    {
        $json = JsonHelper::encodeUnicodeWithUtf8Fallback(['ok' => true]);
        $this->assertSame('{"ok":true}', $json);
    }

    /**
     * encodeUnicodePrettyThrow: массив в читаемый многострочный JSON.
     */
    public function testEncodeUnicodePrettyThrowContainsNewline(): void
    {
        $json = JsonHelper::encodeUnicodePrettyThrow(['a' => 1]);
        $this->assertStringContainsString("\n", $json);
        $this->assertStringContainsString('"a"', $json);
    }
}
