<?php

declare(strict_types=1);

namespace Core\Page;

use Core\Version\Version;
use RuntimeException;

final class PageRepository
{
    public function __construct(
        private readonly string $root,
        private readonly Version $version,
        private readonly string $directory = 'content/pages',
    ) {}

    public function find(string $slug): ?PageResult
    {
        if (preg_match('/^[a-z0-9-]{1,64}$/', $slug) !== 1) {
            return null;
        }

        $file = $this->root . '/' . trim($this->directory, '/') . '/' . $slug . '.php';
        if (!is_file($file)) {
            return null;
        }

        $context = new PageContext($slug, $this->root, $this->version);
        $result = require $file;

        if (is_callable($result)) {
            $result = $result($context);
        }
        if (is_string($result)) {
            return new PageResult($slug, $result);
        }
        if ($result instanceof PageResult) {
            return $result;
        }
        if (!is_array($result)) {
            throw new RuntimeException(sprintf('Page %s must return an array, callable, string or PageResult.', $slug));
        }

        return PageResult::fromArray($result);
    }

    /** @return list<string> */
    public function slugs(): array
    {
        $directory = $this->root . '/' . trim($this->directory, '/');
        $files = glob($directory . '/*.php');
        if ($files === false) {
            return [];
        }

        $slugs = [];
        foreach ($files as $file) {
            $slug = basename($file, '.php');
            if (preg_match('/^[a-z0-9-]{1,64}$/', $slug) === 1) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);

        return $slugs;
    }
}
