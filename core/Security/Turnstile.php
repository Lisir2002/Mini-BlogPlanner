<?php

declare(strict_types=1);

namespace Core\Security;

use Closure;
use Core\Config\Env;
use JsonException;

final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @param list<string> $hostnames */
    public function __construct(
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly array $hostnames = [],
        private readonly string $action = 'login',
        private readonly bool $active = true,
        private readonly ?Closure $transport = null,
    ) {}

    public static function fromEnv(): self
    {
        $siteKey = trim((string) Env::get('TURNSTILE_SITE_KEY', ''));
        $secretKey = trim((string) Env::get('TURNSTILE_SECRET_KEY', ''));
        $active = true;
        $switch = Env::get('TURNSTILE_ENABLED');
        if ($switch !== null && $switch !== '') {
            $active = filter_var($switch, FILTER_VALIDATE_BOOL);
        }

        $configuredHosts = trim((string) Env::get('TURNSTILE_HOSTNAMES', ''));
        $hostnames = [];
        if ($configuredHosts !== '') {
            foreach (explode(',', $configuredHosts) as $host) {
                $normalized = self::normalizeHost($host);
                if ($normalized !== '') {
                    $hostnames[] = $normalized;
                }
            }
        } else {
            $appUrl = trim((string) Env::get('APP_URL', ''));
            $host = $appUrl !== '' ? parse_url($appUrl, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hostnames[] = self::normalizeHost($host);
            }
        }

        $action = trim((string) Env::get('TURNSTILE_ACTION', 'login'));

        return new self(
            siteKey: $siteKey,
            secretKey: $secretKey,
            hostnames: array_values(array_filter($hostnames)),
            action: $action !== '' ? $action : 'login',
            active: $active,
        );
    }

    public function enabled(): bool
    {
        return $this->active && $this->siteKey !== '' && $this->secretKey !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(?string $token): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $payload = http_build_query([
            'secret' => $this->secretKey,
            'response' => $token,
        ]);

        $body = $this->send($payload);
        if ($body === null) {
            return false;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            return false;
        }

        $hostname = self::normalizeHost((string) ($data['hostname'] ?? ''));
        if ($this->hostnames !== [] && !in_array($hostname, $this->hostnames, true)) {
            return false;
        }

        $action = (string) ($data['action'] ?? '');
        if ($action !== '' && $this->action !== '' && !hash_equals($this->action, $action)) {
            return false;
        }

        return true;
    }

    private function send(string $payload): ?string
    {
        if ($this->transport !== null) {
            return ($this->transport)($payload);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($body === false) {
            return null;
        }

        $status = 0;
        $headers = $http_response_header;
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        return $status >= 200 && $status < 300 ? $body : null;
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }
}
