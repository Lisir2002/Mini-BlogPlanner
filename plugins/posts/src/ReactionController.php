<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\PostRepository;
use Core\Content\ReactionRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class ReactionController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        try {
            $db = Connection::fromEnv();
            $post = (new PostRepository($db))->findPublished($slug);
        } catch (Throwable) {
            return $this->error($request, 503, '暂时无法读取文章，请稍后再试。');
        }
        if ($post === null) {
            return $this->error($request, 404, '', '/problems/post-not-found', 'Post Not Found');
        }
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return $this->error($request, 419, '页面已过期，请刷新后重试。', '/problems/csrf-expired', 'Form Expired');
        }

        $rawValue = (string) ($_POST['value'] ?? '');
        if (!in_array($rawValue, ['1', '-1', '0'], true)) {
            return $this->error($request, 422, '赞或踩的取值无效。', '/problems/reaction-invalid', 'Invalid Reaction');
        }
        $value = (int) $rawValue;

        $token = ReactionVisitor::current();
        $newVisitor = $token === null;
        if ($token === null) {
            $token = ReactionVisitor::issue();
        }
        $visitorHash = ReactionVisitor::visitorHash($token);
        $ipHash = ReactionVisitor::ipHash();

        try {
            $reactions = new ReactionRepository($db);
            $before = $reactions->state($post->id, $visitorHash);
            if ($value !== 0 && $before['mine'] === 0 && $reactions->countRecentByIp($ipHash) >= 20) {
                $response = $this->error($request, 429, '操作太频繁，请稍后再试。', '/problems/rate-limited', 'Too Many Requests');
                if ($request->wantsJson()) {
                    $response = $response->withHeader('Retry-After', '60');
                }

                return $response;
            }
            $state = $reactions->set($post->id, $visitorHash, $ipHash, $value);
        } catch (Throwable) {
            return $this->error($request, 503, '暂时无法保存，请稍后再试。');
        }

        $message = $this->message($value, $before['mine']);
        $response = $request->wantsJson()
            ? Response::json([
                'data' => [
                    'slug' => $post->slug,
                    'likes' => $state['likes'],
                    'dislikes' => $state['dislikes'],
                    'mine' => $state['mine'],
                ],
                'message' => $message,
            ])
            : Response::redirect('/posts/' . rawurlencode($post->slug) . '.html?reaction=' . $this->query($value) . '#echo', 303);

        if ($newVisitor) {
            $response = $response->withHeader('Set-Cookie', ReactionVisitor::cookie($token));
        }

        return $response;
    }

    private function message(int $value, int $previous): string
    {
        if ($value === 0) {
            return '已撤回。';
        }
        if ($previous === $value) {
            return '已经记过了。';
        }
        if ($previous === -$value) {
            return $value === 1 ? '已改为赞。' : '已改为踩。';
        }

        return $value === 1 ? '已记下这一赞。' : '已记下这一踩。';
    }

    private function query(int $value): string
    {
        return $value === 1 ? 'liked' : ($value === -1 ? 'disliked' : 'cleared');
    }

    private function error(
        Request $request,
        int $status,
        string $detail,
        string $type = '/problems/service-unavailable',
        string $title = 'Service Unavailable',
    ): Response {
        if ($request->wantsJson()) {
            return Response::problem([
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->path(),
            ], $status);
        }

        return ErrorPage::page($this->theme, $request, $status, $detail, $this->version, $this->site());
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
