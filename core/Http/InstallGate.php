<?php

declare(strict_types=1);

namespace Core\Http;

final class InstallGate
{
    public function __construct(
        private readonly string $lockFile,
        private readonly string $installEntry,
        private readonly string $problemBase = '/problems',
    ) {}

    public function handle(Request $request): ?Response
    {
        if (is_file($this->lockFile)) {
            return null;
        }

        if ($request->path() === '/healthz') {
            return Response::json(['status' => 'not-installed'], 503)
                ->withHeader('Cache-Control', 'no-store');
        }

        if ($request->wantsJson()) {
            return Response::problem([
                'type' => $this->problemBase . '/not-installed',
                'title' => '站点尚未安装',
                'status' => 503,
                'detail' => '请先访问 /install.php 完成安装。',
                'instance' => $request->path(),
            ], 503)->withHeader('Cache-Control', 'no-store');
        }

        if (is_file($this->installEntry)) {
            return Response::redirect('/install.php', 302)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::text('站点尚未安装，且 install.php 不存在。请上传安装入口或使用 CLI 安装。', 503)
            ->withHeader('Cache-Control', 'no-store');
    }
}
