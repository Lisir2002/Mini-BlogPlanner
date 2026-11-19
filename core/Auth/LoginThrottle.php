<?php

declare(strict_types=1);

namespace Core\Auth;

final class LoginThrottle
{
    public function __construct(
        private readonly string $file,
        private readonly int $limit = 5,
        private readonly int $window = 900,
    ) {}

    public function allow(string $ip): bool
    {
        return count($this->bucket($ip)) < $this->limit;
    }

    public function hit(string $ip): void
    {
        $data = $this->read();
        $key = $this->key($ip);
        $data[$key][] = time();
        $this->write($data);
    }

    public function clear(string $ip): void
    {
        $data = $this->read();
        unset($data[$this->key($ip)]);
        $this->write($data);
    }

    /** @return list<int> */
    private function bucket(string $ip): array
    {
        $data = $this->read();
        $values = $data[$this->key($ip)] ?? [];
        if (!is_array($values)) {
            return [];
        }

        $now = time();
        $window = $this->window;

        return array_values(array_filter(
            $values,
            static fn ($value): bool => is_int($value) && $value > $now - $window,
        ));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $tmp = $this->file . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $this->file);
        }
    }

    private function key(string $ip): string
    {
        return hash('sha256', $ip);
    }
}
