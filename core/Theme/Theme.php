<?php

declare(strict_types=1);

namespace Core\Theme;

use JsonException;
use RuntimeException;

final class Theme
{
    /** @param array<string, mixed> $manifest */
    private function __construct(
        private readonly string $directory,
        private readonly string $id,
        private readonly string $templates,
        private readonly array $manifest,
    ) {}

    public static function load(string $root, string $id): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,31}$/', $id) !== 1) {
            throw new RuntimeException('Invalid theme id.');
        }

        $directory = rtrim($root, '/') . '/themes/' . $id;
        $file = $directory . '/theme.json';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Theme %s is not installed.', $id));
        }

        try {
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Theme manifest is invalid.', 0, $exception);
        }
        if (!is_array($manifest) || ($manifest['id'] ?? null) !== $id) {
            throw new RuntimeException('Theme manifest id does not match its directory.');
        }

        $templates = $manifest['templates'] ?? 'templates';
        if (!is_string($templates) || preg_match('/^[a-z0-9-]+$/', $templates) !== 1) {
            throw new RuntimeException('Theme templates directory is invalid.');
        }
        if (!is_dir($directory . '/' . $templates)) {
            throw new RuntimeException('Theme templates directory is missing.');
        }

        $functions = $directory . '/bootstrap.php';
        if (is_file($functions)) {
            require_once $functions;
        }

        return new self($directory, $id, $templates, $manifest);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '0.0.0');
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $file = $this->directory . '/' . $this->templates . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Theme template not found: %s', $template));
        }

        $theme = $this;
        extract($data + ['theme' => $theme], EXTR_SKIP);
        ob_start();
        require $file;

        $content = (string) ob_get_clean();
        if ($template === $layout) {
            return $content;
        }

        return $this->render($layout, $data + ['content' => $content, 'theme' => $theme], $layout);
    }
}
