<?php

declare(strict_types=1);

namespace Plugin\Pages;

use Core\Config\Env;
use Core\Page\PageRepository;
use Core\Plugin\PluginInterface;
use Core\Routing\Route;
use Core\Theme\Theme;
use Core\Version\Version;

final class PagesPlugin implements PluginInterface
{
    private readonly Theme $theme;

    public function __construct(private readonly Version $version)
    {
        $root = dirname(__DIR__, 3);
        $themeId = (string) (Env::get('APP_THEME', 'cream') ?? 'cream');
        $this->theme = Theme::load($root, $themeId);
    }

    public function id(): string
    {
        return 'pages';
    }

    public function priority(): int
    {
        return 40;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            new Route(
                ['GET'],
                '/{slug}',
                new PageController($this->version, $this->theme, new PageRepository($root, $this->version)),
                'pages.show',
            ),
        ];
    }
}
