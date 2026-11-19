<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Request;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(Route $route): void
    {
        $this->routes[] = $route;
    }

    public function match(Request $request): ?RouteMatch
    {
        $path = $this->normalize($request->path());
        $method = $request->method();

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);
            if ($params === null) {
                continue;
            }
            if (!in_array($method, $route->methods(), true) && !($method === 'HEAD' && in_array('GET', $route->methods(), true))) {
                continue;
            }

            return new RouteMatch($route, $params);
        }

        return null;
    }

    /** @return list<string> */
    public function allowedMethods(string $path): array
    {
        $normalized = $this->normalize($path);
        $methods = [];

        foreach ($this->routes as $route) {
            if ($route->matchPath($normalized) === null) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $methods[$method] = true;
                if ($method === 'GET') {
                    $methods['HEAD'] = true;
                }
            }
        }

        return array_keys($methods);
    }

    public function normalize(string $path): string
    {
        if ($path === '/index.html') {
            return '/';
        }
        if (str_ends_with($path, '.html')) {
            $path = substr($path, 0, -5);
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
