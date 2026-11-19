<?php

declare(strict_types=1);

namespace Core;

use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Router;
use Throwable;

final class Kernel
{
    /**
     * @param null|callable(Request, int, string, string): Response $errorRenderer
     */
    public function __construct(
        private readonly Router $router,
        private readonly string $logDirectory,
        private $errorRenderer = null,
    ) {}

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request);

        if ($match === null) {
            $allowed = $this->router->allowedMethods($request->path());
            if ($allowed !== []) {
                return $this->error($request, 405, 'Method Not Allowed', '该地址不支持此请求方法。')
                    ->withHeader('Allow', implode(', ', $allowed));
            }

            return $this->error($request, 404, 'Not Found', '请求的资源不存在。');
        }

        try {
            return ($match->route()->handler())($request, $match->parameters());
        } catch (Throwable $exception) {
            $this->log($request, $exception);

            return $this->error($request, 500, 'Internal Server Error', '服务暂时不可用，请稍后再试。');
        }
    }

    private function error(Request $request, int $status, string $title, string $detail): Response
    {
        if ($request->wantsJson()) {
            return Response::problem([
                'type' => '/problems/' . match ($status) {
                    404 => 'not-found',
                    405 => 'method-not-allowed',
                    default => 'internal-error',
                },
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->path(),
            ], $status);
        }

        if ($this->errorRenderer !== null) {
            return ($this->errorRenderer)($request, $status, $title, $detail);
        }

        return Response::text($title, $status);
    }

    private function log(Request $request, Throwable $exception): void
    {
        if (!is_dir($this->logDirectory) && !mkdir($this->logDirectory, 0775, true) && !is_dir($this->logDirectory)) {
            return;
        }

        $line = json_encode([
            'at' => gmdate('c'),
            'path' => $request->path(),
            'method' => $request->method(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($line)) {
            file_put_contents($this->logDirectory . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
