<?php

declare(strict_types=1);

namespace Core\Routing;

use Closure;
use InvalidArgumentException;

final class Route
{
    /** @var list<string> */
    private readonly array $methods;
    /** @var list<string> */
    private readonly array $parameterNames;
    private readonly string $regex;
    private readonly Closure $handler;

    /**
     * @param list<string> $methods
     * @param callable(\Core\Http\Request, array<string, string>): \Core\Http\Response $handler
     */
    public function __construct(
        array $methods,
        private readonly string $pattern,
        callable $handler,
        private readonly ?string $name = null,
    ) {
        $this->handler = Closure::fromCallable($handler);
        if ($pattern === '' || !str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException('Route pattern must start with /.');
        }
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
        [$this->regex, $this->parameterNames] = $this->compile($pattern);
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function handler(): Closure
    {
        return $this->handler;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /** @return array<string, string>|null */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $index => $name) {
            $params[$name] = rawurldecode((string) ($matches[$index + 1] ?? ''));
        }

        return $params;
    }

    /** @return array{0:string,1:list<string>} */
    private function compile(string $pattern): array
    {
        $names = [];
        $regex = '';
        $offset = 0;

        while (preg_match('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $token = $match[0][0];
            $position = $match[0][1];
            $regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
            $regex .= '([^/]+)';
            $names[] = $match[1][0];
            $offset = $position + strlen($token);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return ['#^' . $regex . '$#u', $names];
    }
}
