<?php

declare(strict_types=1);

namespace Plugin\Web;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Plugin\PluginInterface;
use Core\Routing\Route;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;

final class WebPlugin implements PluginInterface
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
        return 'web';
    }

    public function priority(): int
    {
        return 20;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return [
            new Route(['GET'], '/', new HomeController($this->version, $this->theme), 'web.home'),
        ];
    }

    public function errorPage(Request $request, int $status, string $title, string $detail): Response
    {
        return ErrorPage::response($this->theme, $this->version, $request, $status, $title, $detail, $this->site());
    }

    /** @return array{title:string,description:string,language:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
        ];
    }
}
