<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Content\SearchTerm;
use Core\Http\Request;
use Core\Http\Response;

/**
 * 旧的搜索地址只做兼容跳转，搜索结果由首页顶栏的搜索蒙版即时读取。
 */
final class SearchController
{
    public function __invoke(Request $request): Response
    {
        $query = SearchTerm::normalize($request->query()['q'] ?? null);
        $target = $query === '' ? '/' : '/?search=' . rawurlencode($query);

        return Response::redirect($target, 302)
            ->withHeader('X-Robots-Tag', 'noindex, follow');
    }
}
