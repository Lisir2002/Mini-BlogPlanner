<?php

declare(strict_types=1);

namespace Core\Plugin;

use Core\Routing\Route;

final class PluginRegistry
{
    /** @var array<string, PluginInterface> */
    private array $plugins = [];

    public function register(PluginInterface $plugin): void
    {
        $this->plugins[$plugin->id()] = $plugin;
    }

    public function has(string $id): bool
    {
        return isset($this->plugins[$id]);
    }

    public function get(string $id): ?PluginInterface
    {
        return $this->plugins[$id] ?? null;
    }

    /** @return list<PluginInterface> */
    public function all(): array
    {
        $plugins = array_values($this->plugins);
        usort($plugins, static fn (PluginInterface $a, PluginInterface $b): int => $a->priority() <=> $b->priority());

        return $plugins;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->all() as $plugin) {
            foreach ($plugin->routes() as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
