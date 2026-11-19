<?php

declare(strict_types=1);

namespace Core\Page;

use InvalidArgumentException;

final class PageResult
{
    /**
     * @param list<string> $styles
     * @param list<string> $scripts
     */
    public function __construct(
        private readonly string $title,
        private readonly string $content,
        private readonly array $styles = [],
        private readonly array $scripts = [],
        private readonly string $template = 'page',
    ) {
        if ($template !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $template) !== 1) {
            throw new InvalidArgumentException('Page template name is invalid.');
        }
        foreach ([$styles, $scripts] as $assets) {
            foreach ($assets as $asset) {
                if ($asset === '' || str_contains($asset, '..')) {
                    throw new InvalidArgumentException('Page asset path is invalid.');
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $styles = $data['styles'] ?? [];
        $scripts = $data['scripts'] ?? [];

        return new self(
            (string) ($data['title'] ?? ''),
            (string) ($data['content'] ?? ''),
            is_array($styles) ? array_values(array_filter($styles, 'is_string')) : [],
            is_array($scripts) ? array_values(array_filter($scripts, 'is_string')) : [],
            (string) ($data['template'] ?? 'page'),
        );
    }

    public function title(): string
    {
        return $this->title;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return list<string> */
    public function styles(): array
    {
        return $this->styles;
    }

    /** @return list<string> */
    public function scripts(): array
    {
        return $this->scripts;
    }

    public function template(): string
    {
        return $this->template;
    }
}
