<?php

declare(strict_types=1);

namespace Core\Plugin;

use Core\Version\Version;

final class PluginLoader
{
    public function __construct(private readonly string $pluginsDirectory) {}

    public function load(Version $version): PluginRegistry
    {
        $registry = new PluginRegistry();
        $manifests = glob($this->pluginsDirectory . '/*/plugin.json') ?: [];

        foreach ($manifests as $file) {
            $manifest = $this->manifest($file);
            if ($manifest === null || ($manifest['runtime'] ?? true) !== true || ($manifest['enabled'] ?? true) !== true) {
                continue;
            }

            $class = $manifest['class'] ?? null;
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            /** @var class-string $class */
            $plugin = new $class($version);
            if ($plugin instanceof PluginInterface) {
                $registry->register($plugin);
            }
        }

        return $registry;
    }

    /** @return array<string, mixed>|null */
    private function manifest(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
