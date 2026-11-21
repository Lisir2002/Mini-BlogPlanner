<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

/**
 * 读取 GitHub 上的正式发布，只返回本项目固定仓库中的稳定版本。
 * 页面和 root 更新助手共用同一份资产匹配规则，避免两处判断不一致。
 */
final class ReleaseCatalog
{
    public const REPOSITORY = 'Lisir2002/Mini-BlogPlanner';

    private const API = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases';
    private const USER_AGENT = 'Mini-BlogPlanner-Updater/1.0';

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $payload = $this->get(self::API . '/latest', true);

        return $payload === null ? null : $this->parse($payload);
    }

    /** @return array<string, mixed> */
    public function release(string $tag): array
    {
        if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) !== 1) {
            throw new RuntimeException('发布标签格式无效。');
        }

        $payload = $this->get(self::API . '/tags/' . rawurlencode($tag), false);
        if ($payload === null) {
            throw new RuntimeException(sprintf('GitHub 上不存在发布 %s。', $tag));
        }

        return $this->parse($payload);
    }

    public function download(string $url, string $destination): void
    {
        if (preg_match('#^https://#i', $url) !== 1) {
            throw new RuntimeException('只允许从 HTTPS 地址下载发布资产。');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 120,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: application/octet-stream\r\n",
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new RuntimeException('无法下载发布资产。');
        }

        $temporary = $destination . '.part';
        $target = fopen($temporary, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('无法写入发布资产。');
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        if ($copied === false || $copied <= 0) {
            @unlink($temporary);
            throw new RuntimeException('发布资产下载不完整。');
        }
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('无法保存发布资产。');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parse(array $payload): array
    {
        $tag = (string) ($payload['tag_name'] ?? '');
        if (preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $matches) !== 1) {
            throw new RuntimeException('最新发布不是稳定的 v<major>.<minor>.<patch> 标签。');
        }
        if (($payload['draft'] ?? false) === true || ($payload['prerelease'] ?? false) === true) {
            throw new RuntimeException('页面只允许更新到正式发布，不接受草稿或预发布版。');
        }

        $assets = $payload['assets'] ?? null;
        if (!is_array($assets)) {
            throw new RuntimeException('发布缺少资产列表。');
        }

        $zipName = 'Mini-BlogPlanner-' . $tag . '.zip';
        $shaName = $zipName . '.sha256';
        $zip = $this->asset($assets, $zipName);
        $sha = $this->asset($assets, $shaName);
        if ($zip === null || $sha === null) {
            throw new RuntimeException(sprintf('发布 %s 缺少 zip 或 sha256 资产。', $tag));
        }

        $body = (string) ($payload['body'] ?? '');
        $body = str_replace("\r\n", "\n", $body);

        return [
            'tag' => $tag,
            'app' => $matches[1],
            'name' => trim((string) ($payload['name'] ?? $tag)),
            'body' => mb_substr($body, 0, 4000),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'html_url' => (string) ($payload['html_url'] ?? ''),
            'zip' => $zip,
            'sha256' => $sha,
        ];
    }

    /**
     * @param array<int, mixed> $assets
     * @return array{name:string,url:string,size:int}|null
     */
    private function asset(array $assets, string $name): ?array
    {
        foreach ($assets as $asset) {
            if (!is_array($asset) || (string) ($asset['name'] ?? '') !== $name) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');
            if (preg_match('#^https://#i', $url) !== 1) {
                throw new RuntimeException('发布资产地址不是 HTTPS。');
            }
            $size = (int) ($asset['size'] ?? 0);
            if ($size <= 0 || $size > 104857600) {
                throw new RuntimeException('发布资产大小异常。');
            }

            return ['name' => $name, 'url' => $url, 'size' => $size];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function get(string $url, bool $allowMissing): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => "Accept: application/vnd.github+json\r\n"
                    . 'User-Agent: ' . self::USER_AGENT . "\r\n"
                    . "X-GitHub-Api-Version: 2022-11-28\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = http_get_last_response_headers();
        $status = 0;
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($status === 404 && $allowMissing) {
            return null;
        }
        if ($status === 403 || $status === 429) {
            throw new RuntimeException('GitHub 接口访问受限，请稍后再试。');
        }
        if ($body === false || $status !== 200) {
            throw new RuntimeException(sprintf('读取 GitHub 发布失败（HTTP %d）。', $status));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub 发布数据格式无效。');
        }

        return $data;
    }
}
