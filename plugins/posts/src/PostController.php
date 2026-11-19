<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\CommentRepository;
use Core\Content\PostRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use Throwable;

final class PostController
{
    public function __construct(
        private readonly Version $version,
        private readonly Theme $theme,
    ) {}

    /** @param array<string, string> $params */
    public function __invoke(Request $request, array $params): Response
    {
        return $this->render($request, $params);
    }

    /**
     * @param array<string, string> $params
     * @param array{errors?:list<string>,values?:array{author_name:string,author_email:string,body:string},reply_to?:?int} $commentState
     */
    public function render(Request $request, array $params, array $commentState = [], int $status = 200): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        try {
            $db = Connection::fromEnv();
            $post = (new PostRepository($db))->findPublished($slug);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取文章，请稍后再试。', $this->version, $this->site());
        }
        if ($post === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        try {
            $comments = new CommentRepository($db);
            $threads = $comments->threadsForPost($post->id);
            $commentCount = $comments->countApprovedForPost($post->id);
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取留言，请稍后再试。', $this->version, $this->site());
        }

        $errors = is_array($commentState['errors'] ?? null) ? $commentState['errors'] : [];
        $values = is_array($commentState['values'] ?? null) ? $commentState['values'] : [
            'author_name' => '',
            'author_email' => '',
            'body' => '',
        ];

        $replyTo = $commentState['reply_to'] ?? null;
        if (!is_int($replyTo)) {
            $rawReply = (string) ($request->query()['reply'] ?? '');
            $replyTo = preg_match('/^[1-9][0-9]*$/', $rawReply) === 1 ? (int) $rawReply : null;
        }
        $replyName = null;
        if ($replyTo !== null) {
            try {
                $parent = $comments->findApproved($replyTo, $post->id, true);
            } catch (Throwable) {
                $parent = null;
            }
            if ($parent === null) {
                $replyTo = null;
            } else {
                $replyName = $parent->authorName;
            }
        }

        $notice = match ((string) ($request->query()['comment'] ?? '')) {
            'pending' => '留言已提交，审核后会显示在这里。',
            'approved' => '留言已通过审核。',
            'spam' => '留言已移入垃圾箱。',
            default => null,
        };

        $html = $this->theme->render('post', [
            'site' => $this->site(),
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $post->title,
            'current' => 'posts',
            'post' => $post,
            'comment_threads' => $threads,
            'comment_count' => $commentCount,
            'comment_form_at' => CommentForm::issue(),
            'comment_csrf' => Csrf::token(),
            'comment_errors' => $errors,
            'comment_values' => $values,
            'comment_notice' => $notice,
            'comment_reply_to' => $replyTo,
            'comment_reply_name' => $replyName,
            'page_styles' => ['posts.css', 'comments.css'],
        ]);

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
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
