<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;

trait AdminErrors
{
    private function adminError(Request $request, int $status, string $detail = ''): Response
    {
        return ErrorPage::admin($this->theme, $request, $status, $detail);
    }
}
