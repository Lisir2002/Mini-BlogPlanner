<?php

declare(strict_types=1);

namespace Core\Http;

use JsonException;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {}

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    /** @param array<string, mixed> $data
     * @throws JsonException
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $problem */
    public static function problem(array $problem, int $status = 500): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
            json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location], '');
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function binary(string $body, string $mime, int $status = 200): self
    {
        return new self($status, ['Content-Type' => $mime], $body);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }
}
