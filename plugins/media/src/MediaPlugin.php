<?php

declare(strict_types=1);

namespace Plugin\Media;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Plugin\PluginInterface;
use Core\Routing\Route;
use Core\Theme\Theme;

final class MediaPlugin implements PluginInterface
{
    private readonly Theme $theme;

    public function __construct()
    {
        $root = dirname(__DIR__, 3);
        $themeId = (string) (Env::get('APP_THEME', 'cream') ?? 'cream');
        $this->theme = Theme::load($root, $themeId);
    }

    public function id(): string
    {
        return 'media';
    }

    public function priority(): int
    {
        return 35;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $root = dirname(__DIR__, 3);
        $theme = $this->theme;

        return [
            new Route(['GET'], '/media/{id}/{name}', static fn (Request $request, array $params): Response => (new MediaController($root, $theme))->show($request, $params), 'media.show'),
        ];
    }
}
