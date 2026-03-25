<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function max;
use function min;
use function strtolower;
use function trim;

/**
 * Инструмент `VarListTool`: возвращает список результатов для текущего `sessionKey`.
 */
final class VarListTool extends AVarTool
{
    public function __construct(
        string $name = 'var_list',
        string $description = 'Список всех результатов в .store для текущего sessionKey (метки и метаданные).',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name       : 'page_size',
                type       : PropertyType::INTEGER,
                description: 'Размер страницы (для постраничного списка). Если не задан — вернуть весь список.',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'page',
                type       : PropertyType::INTEGER,
                description: 'Номер страницы (1-based). Используется вместе с page_size.',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'query',
                type       : PropertyType::STRING,
                description: 'Строка поиска по name и description (case-insensitive). Если не задана — без фильтра.',
                required   : false,
            ),
        ];
    }

    public function __invoke(?int $page_size = null, ?int $page = null, ?string $query = null): string
    {
        $storage    = $this->getStorage();
        $sessionKey = $this->getSessionKey();

        $items = $storage->list($sessionKey);
        $queryNorm = $query !== null ? trim($query) : '';

        if ($queryNorm !== '') {
            $q = strtolower($queryNorm);
            $items = array_values(array_filter(
                $items,
                static function ($item) use ($q): bool {
                    $name = strtolower((string) ($item->name ?? ''));
                    $desc = strtolower((string) ($item->description ?? ''));
                    return str_contains($name, $q) || str_contains($desc, $q);
                }
            ));
        }

        $totalCount = count($items);

        $pageSizeOut = null;
        $pageOut = null;
        if ($page_size !== null) {
            $pageSizeOut = max(1, min(1000, $page_size));
            $pageOut     = $page !== null ? max(1, $page) : 1;
            $offset      = ($pageOut - 1) * $pageSizeOut;
            $items       = array_slice($items, $offset, $pageSizeOut);
        }

        return $this->resultJson(
            new VarToolResultDto(
                action    : 'list',
                success   : true,
                message   : 'OK',
                sessionKey: $sessionKey,
                items     : $items,
                count     : count($items),
                totalCount: $totalCount,
                page      : $pageOut,
                pageSize  : $pageSizeOut,
                query     : $queryNorm !== '' ? $queryNorm : null,
            )
        );
    }
}
