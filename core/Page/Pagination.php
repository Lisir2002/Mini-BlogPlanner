<?php

declare(strict_types=1);

namespace Core\Page;

/**
 * 全站列表的统一分页口径。
 *
 * 页面一次只铺十条，翻页由调用方按自己的 URL 规则生成，
 * 避免各控制器把条数上限散落在不同常量里。
 */
final class Pagination
{
    public const PER_PAGE = 10;

    /** @return array{per_page:int,page:int,pages:int,total:int,offset:int} */
    public static function resolve(int $requestedPage, int $total, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, $perPage);
        $total = max(0, $total);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $requestedPage));

        return [
            'per_page' => $perPage,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * 返回首尾页、当前页附近和省略号占位的页码窗口。
     *
     * @return list<int|null>
     */
    public static function window(int $page, int $pages, int $radius = 2): array
    {
        if ($pages <= 1) {
            return [];
        }

        $page = max(1, min($pages, $page));
        $start = max(2, $page - $radius);
        $end = min($pages - 1, $page + $radius);
        $items = [1];

        if ($start > 2) {
            $items[] = null;
        }
        for ($number = $start; $number <= $end; $number++) {
            $items[] = $number;
        }
        if ($end < $pages - 1) {
            $items[] = null;
        }
        if (end($items) !== $pages) {
            $items[] = $pages;
        }

        return $items;
    }
}
