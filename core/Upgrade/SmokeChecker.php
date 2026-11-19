<?php

declare(strict_types=1);

namespace Core\Upgrade;

use Core\Config\Env;
use RuntimeException;

final class SmokeChecker
{
    public function __construct(private readonly ReleaseRepository $releases) {}

    /** @param array<string, mixed> $plan */
    public function check(array $plan): void
    {
        $release = (string) $plan['to']['release'];
        $app = (string) $plan['to']['app'];
        $schema = (int) $plan['to']['schema'];

        if ($this->releases->current() !== $release) {
            throw new RuntimeException('current release does not match the plan.');
        }

        $versionFile = $this->releases->manifest($release, false)->directory . '/version.json';
        if (!is_file($versionFile)) {
            throw new RuntimeException('current release has no version.json.');
        }
        $version = json_decode((string) file_get_contents($versionFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($version) || ($version['app'] ?? '') !== $app || (int) ($version['schema'] ?? 0) !== $schema) {
            throw new RuntimeException('current release version does not match the plan.');
        }

        $url = rtrim(Env::get('APP_URL', '') ?? '', '/');
        if ($url === '') {
            return;
        }

        $body = $this->fetch($url . '/healthz');
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
            throw new RuntimeException('healthz did not return ok.');
        }
        if (($data['app'] ?? '') !== $app || (int) ($data['schema'] ?? 0) !== $schema) {
            throw new RuntimeException('healthz version does not match the plan.');
        }
    }

    private function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException(sprintf('Smoke request failed: %s', $url));
        }

        $status = 0;
        $headers = http_get_last_response_headers();
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Smoke request returned HTTP %d.', $status));
        }

        return $body;
    }
}
