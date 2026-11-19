<?php

declare(strict_types=1);

namespace Core\Plugin;

use Core\Routing\Route;

interface PluginInterface
{
    public function id(): string;

    public function priority(): int;

    /** @return list<Route> */
    public function routes(): array;
}
