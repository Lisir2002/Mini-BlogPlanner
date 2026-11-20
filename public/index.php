<?php

declare(strict_types=1);

use Core\Config\Env;
use Core\Http\InstallGate;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\ResponseEmitter;
use Core\Http\Session;
use Core\Kernel;
use Core\Plugin\PluginLoader;
use Core\Routing\Router;
use Core\Version\Version;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
require $root . '/bootstrap/storage.php';
Env::loadWithFallback($root);
$storage = cream_storage_path($root);
$request = Request::fromGlobals();
$statelessPaths = ['/feed', '/feed.xml', '/atom.xml', '/feed.json', '/sitemap.xml', '/robots.txt', '/healthz'];
$path = $request->path();
$stateless = in_array($path, $statelessPaths, true) || $path === '/archive' || $path === '/archive.html' || str_starts_with($path, '/archive/');
if (!$stateless) {
    Session::start();
}

$version = Version::fromFile($root . '/version.json');
$emitter = new ResponseEmitter();

$gate = new InstallGate($storage . '/installed.lock', $root . '/public/install.php');
$response = $gate->handle($request);

if ($response === null) {
    try {
        $registry = (new PluginLoader($root . '/plugins'))->load($version);
        $router = new Router();
        foreach ($registry->routes() as $route) {
            $router->add($route);
        }

        $web = $registry->get('web');
        $errorRenderer = $web !== null && method_exists($web, 'errorPage')
            ? [$web, 'errorPage']
            : null;

        $response = (new Kernel($router, $storage . '/logs', $errorRenderer))->handle($request);
    } catch (Throwable) {
        $response = Response::problem([
            'type' => '/problems/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => 'The service is temporarily unavailable.',
            'instance' => $request->path(),
        ], 503);
    }
}

$response = $response
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('X-Frame-Options', 'DENY')
    ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
    ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

if (!array_key_exists('Cache-Control', $response->headers())) {
    $response = $response->withHeader('Cache-Control', 'no-store');
}

$emitter->emit($response);
