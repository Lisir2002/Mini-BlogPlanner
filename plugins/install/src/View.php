<?php

declare(strict_types=1);

namespace Install;

final class View
{
    public function __construct(private readonly string $directory) {}

    /** @param array<string, mixed> $data */
    public function render(string $step, array $data): void
    {
        $data['step'] = $step;
        $content = $this->capture('step-' . $step . '.php', $data);
        echo $this->capture('layout.php', $data + ['content' => $content]);
    }

    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = rtrim($this->directory, '/') . '/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Installer view not found: %s', $template));
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
