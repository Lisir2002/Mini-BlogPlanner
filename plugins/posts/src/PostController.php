<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Config\Env;
use Core\Content\CommentRepository;
use Core\Content\PostRepository;
use Core\Content\ReactionRepository;
use Core\Database\Connection;
use Core\Http\Csrf;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme\ErrorPage;
use Core\Theme\Theme;
use Core\Version\Version;
use DateTimeImmutable;
use DateTimeZone;
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
     * @param array{errors?:list<string>,values?:array{author_name:string,author_email:string,body:string,email_enabled?:bool},reply_to?:?int} $commentState
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

        $reactionState = ['likes' => 0, 'dislikes' => 0, 'mine' => 0];
        $visitorToken = ReactionVisitor::current();
        try {
            $reactionState = (new ReactionRepository($db))->state(
                $post->id,
                $visitorToken === null ? null : ReactionVisitor::visitorHash($visitorToken),
            );
        } catch (Throwable) {
            // 回声读取失败不能阻断文章和留言。
        }

        $errors = is_array($commentState['errors'] ?? null) ? $commentState['errors'] : [];
        $values = is_array($commentState['values'] ?? null) ? $commentState['values'] : [
            'author_name' => '',
            'author_email' => '',
            'body' => '',
            'email_enabled' => false,
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
        $reactionNotice = match ((string) ($request->query()['reaction'] ?? '')) {
            'liked' => '已记下这一赞。',
            'disliked' => '已记下这一踩。',
            'cleared' => '已撤回。',
            default => null,
        };
        $csrf = Csrf::token();
        $site = $this->site();
        $description = $post->excerpt !== '' ? $post->excerpt : $site['description'];
        $canonical = $this->baseUrl($request) . '/posts/' . rawurlencode($post->slug) . '.html';
        $shareMeta = [
            'type' => 'article',
            'url' => $canonical,
            'title' => $post->title,
            'description' => $description,
            'published' => $this->iso($post->publishedAt, $site['timezone']),
            'modified' => $this->iso($post->updatedAt, $site['timezone']),
            'tags' => $post->tags,
        ];

        $html = $this->theme->render('post', [
            'site' => $site,
            'app' => $this->version->app(),
            'schema' => $this->version->schema(),
            'page_title' => $post->title,
            'page_description' => $description,
            'canonical_url' => $canonical,
            'share_meta' => $shareMeta,
            'current' => 'posts',
            'post' => $post,
            'comment_threads' => $threads,
            'comment_count' => $commentCount,
            'comment_form_at' => CommentForm::issue(),
            'comment_csrf' => $csrf,
            'comment_errors' => $errors,
            'comment_values' => $values,
            'comment_notice' => $notice,
            'comment_reply_to' => $replyTo,
            'comment_reply_name' => $replyName,
            'reaction_likes' => $reactionState['likes'],
            'reaction_dislikes' => $reactionState['dislikes'],
            'reaction_mine' => $reactionState['mine'],
            'reaction_csrf' => $csrf,
            'reaction_notice' => $reactionNotice,
            'page_styles' => ['posts.css', 'comments.css', 'reactions.css'],
            'page_scripts' => ['comments.js', 'reactions.js', 'share.js'],
        ]);

        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    private function baseUrl(Request $request): string
    {
        $configured = trim((string) Env::get('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = trim((string) ($request->header('host') ?? 'localhost'));

        return 'http://' . ($host === '' ? 'localhost' : $host);
    }

    private function iso(string $value, string $timezone): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($timezone)))->format(DATE_ATOM);
        } catch (Throwable) {
            return $value;
        }
    }

    /** @return array{title:string,description:string,language:string,timezone:string} */
    private function site(): array
    {
        return [
            'title' => (string) Env::get('APP_NAME', '日记Planner'),
            'description' => (string) Env::get('APP_DESCRIPTION', '一个安静写字的地方。'),
            'language' => (string) Env::get('APP_LOCALE', 'zh-CN'),
            'timezone' => (string) Env::get('APP_TIMEZONE', 'UTC'),
        ];
    }
}
