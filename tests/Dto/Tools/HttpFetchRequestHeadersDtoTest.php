<?php

declare(strict_types=1);

namespace Tests\Dto\Tools;

use app\modules\neuron\classes\dto\tools\HttpFetchRequestHeadersDto;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты DTO исходящих заголовков для HttpFetchTool.
 */
final class HttpFetchRequestHeadersDtoTest extends TestCase
{
    /**
     * Дефолтный User-Agent должен идентифицировать Firefox и движок Gecko (как в реальном браузере).
     */
    public function testFirefoxDefaultsContainsFirefoxAndGeckoInUserAgent(): void
    {
        $dto = HttpFetchRequestHeadersDto::firefoxDefaults();
        $block = $dto->toStreamHeaderString();

        $this->assertStringContainsString('Firefox/', $block);
        $this->assertStringContainsString('Gecko/20100101', $block);
        $this->assertStringContainsString('Mozilla/5.0', $block);
    }

    /**
     * withHeader добавляет новую строку в блок заголовков для stream context.
     */
    public function testWithHeaderAppendsCustomHeader(): void
    {
        $dto = HttpFetchRequestHeadersDto::firefoxDefaults()
            ->withHeader('X-Test', 'one');

        $this->assertStringContainsString('X-Test: one', $dto->toStreamHeaderString());
    }

    /**
     * Пустое имя заголовка после trim должно приводить к InvalidArgumentException.
     */
    public function testWithHeaderEmptyNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('пустым');

        HttpFetchRequestHeadersDto::empty()->withHeader('   ', 'value');
    }

    /**
     * Символы \r и \n в значении заменяются на пробел, чтобы не внедрить дополнительные заголовки.
     */
    public function testValueWithCarriageReturnAndNewlineIsSanitized(): void
    {
        $dto = HttpFetchRequestHeadersDto::empty()->withHeader('X-Inject', "a\r\nBogus: evil");

        $block = $dto->toStreamHeaderString();
        $this->assertStringNotContainsString("\r\nBogus", $block);
        $this->assertStringContainsString('X-Inject: a  Bogus: evil', $block);
    }

    /**
     * Только символ \r в значении также санитизируется.
     */
    public function testValueWithOnlyCrIsSanitized(): void
    {
        $dto = HttpFetchRequestHeadersDto::empty()->withHeader('X', "a\rb");
        $this->assertSame("X: a b\r\n", $dto->toStreamHeaderString());
    }

    /**
     * Повторный withHeader с тем же логическим именем (другой регистр) перезаписывает значение.
     */
    public function testWithHeaderSameNameDifferentCaseReplaces(): void
    {
        $dto = HttpFetchRequestHeadersDto::empty()
            ->withHeader('X-Token', 'first')
            ->withHeader('x-token', 'second');

        $this->assertSame("x-token: second\r\n", $dto->toStreamHeaderString());
    }

    /**
     * merge перекрывает заголовки второго набора при совпадении ключа без учёта регистра.
     */
    public function testMergeOverridesCaseInsensitive(): void
    {
        $a = HttpFetchRequestHeadersDto::empty()->withHeader('User-Agent', 'UA-one');
        $b = HttpFetchRequestHeadersDto::empty()->withHeader('user-agent', 'UA-two');

        $merged = $a->merge($b);
        $this->assertStringContainsString('UA-two', $merged->toStreamHeaderString());
        $this->assertStringNotContainsString('UA-one', $merged->toStreamHeaderString());
    }

    /**
     * merge с пустым вторым аргументом возвращает эквивалент первого набора.
     */
    public function testMergeWithEmptyOtherLeavesFirstUnchanged(): void
    {
        $a = HttpFetchRequestHeadersDto::empty()->withHeader('A', '1');
        $merged = $a->merge(HttpFetchRequestHeadersDto::empty());

        $this->assertSame($a->toStreamHeaderString(), $merged->toStreamHeaderString());
    }

    /**
     * Пустой набор даёт пустую строку заголовков (без завершающего CRLF для непустого — здесь пусто).
     */
    public function testEmptyDtoProducesEmptyStreamString(): void
    {
        $this->assertSame('', HttpFetchRequestHeadersDto::empty()->toStreamHeaderString());
    }

    /**
     * Имя заголовка обрезается по trim; в результате попадает уже обрезанное имя.
     */
    public function testWithHeaderTrimsName(): void
    {
        $dto = HttpFetchRequestHeadersDto::empty()->withHeader('  X-Trim  ', 'v');
        $this->assertStringContainsString('X-Trim: v', $dto->toStreamHeaderString());
    }

    /**
     * firefoxDefaults объединяется с дополнительным заголовком: оба присутствуют в блоке.
     */
    public function testFirefoxDefaultsMergeWithAuthorizationContainsBoth(): void
    {
        $extra = HttpFetchRequestHeadersDto::empty()->withHeader('Authorization', 'Bearer z');
        $merged = HttpFetchRequestHeadersDto::firefoxDefaults()->merge($extra);

        $block = $merged->toStreamHeaderString();
        $this->assertStringContainsString('Authorization: Bearer z', $block);
        $this->assertStringContainsString('User-Agent:', $block);
    }

    /**
     * Перекрытие User-Agent во втором наборе заменяет дефолтный Firefox UA.
     */
    public function testMergeOverridesDefaultUserAgentFromFirefox(): void
    {
        $customUa = HttpFetchRequestHeadersDto::empty()->withHeader('User-Agent', 'CustomBot/1.0');
        $merged = HttpFetchRequestHeadersDto::firefoxDefaults()->merge($customUa);

        $block = $merged->toStreamHeaderString();
        $this->assertStringContainsString('CustomBot/1.0', $block);
        $this->assertStringNotContainsString('Firefox/128.0', $block);
    }
}
