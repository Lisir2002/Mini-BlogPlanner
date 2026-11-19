<?php

declare(strict_types=1);

namespace Install;

final class RateLimiter
{
    public function __construct(
        private readonly string $file,
        private readonly int $limit = 10,
        private readonly int $window = 60,
    ) {}

    public function allow(string $ip): bool
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            $data = $raw === false ? [] : json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            $now = time();
            $window = $this->window;
            $key = hash('sha256', $ip);
            $bucket = is_array($data[$key] ?? null) ? $data[$key] : [];
            $bucket = array_values(array_filter(
                $bucket,
                static fn ($time): bool => is_int($time) && $time > $now - $window,
            ));

            if (count($bucket) >= $this->limit) {
                $data[$key] = $bucket;
                $this->write($handle, $data);

                return false;
            }

            $bucket[] = $now;
            $data[$key] = $bucket;
            $this->write($handle, $data);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle
     *  @param array<string, mixed> $data
     */
    private function write($handle, array $data): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
        fflush($handle);
    }
}
