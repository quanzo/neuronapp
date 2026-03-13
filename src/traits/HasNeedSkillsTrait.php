<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\AbstractPromptWithParams;

/**
 * Трейт для компонент с опцией "skills".
 *
 * Предоставляет метод {@see getNeedSkills()} для получения списка
 * зависимых навыков на основе опции "skills" в блоке настроек.
 * Опция "skills" должна быть строкой с именами компонент через запятую,
 * пробелы вокруг имен обрезаются. При этом имя самого компонента
 * исключается из результата, чтобы избежать самоссылок и рекурсии.
 *
 * Трейт ожидает, что класс-носитель наследуется от {@see AbstractPromptWithParams}
 * и, соответственно, предоставляет защищенный метод {@see AbstractPromptWithParams::parseSkills()}.
 */
trait HasNeedSkillsTrait
{
    /**
     * Возвращает имена зависимых навыков (Skill), которые нужно подключить.
     *
     * Источник списка — опция "skills" в блоке настроек компонента.
     * Нестроковые значения опции считаются некорректными и приводят
     * к пустому результату.
     *
     * @return list<string> Список имен навыков без имени самого компонента.
     */
    public function getNeedSkills(): array
    {
        /** @var AbstractPromptWithParams $this */
        return $this->parseSkills(true);
    }
}
