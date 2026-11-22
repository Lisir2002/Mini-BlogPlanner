<?php

declare(strict_types=1);

namespace Plugin\Posts;

use Core\Audit\AuditRepository;
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

final class CommentController
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
            return ErrorPage::page($this->theme, $request, 503, '暂时无法读取文章，请稍后再试。', $this->version, $this->site());
        }
        if ($post === null) {
            return ErrorPage::page($this->theme, $request, 404, '', $this->version, $this->site());
        }

        if (!Csrf::validate(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null)) {
            return ErrorPage::page($this->theme, $request, 419, '留言表单已过期，请刷新页面后重试。', $this->version, $this->site());
        }

        if (!CommentForm::validate(isset($_POST['_form_at']) ? (string) $_POST['_form_at'] : null)) {
            return $this->error($request, $params, ['留言提交得太快或页面停留过久，请刷新后重试。'], $this->values());
        }

        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            return $this->error($request, $params, ['提交失败，请稍后再试。'], $this->values());
        }

        $name = $this->text((string) ($_POST['author_name'] ?? ''), 40);
        $notifyEmail = (string) ($_POST['notify_email'] ?? '') === '1';
        $email = $notifyEmail ? $this->text((string) ($_POST['author_email'] ?? ''), 190) : '';
        $body = $this->text((string) ($_POST['body'] ?? ''), 1000);
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors[] = '称呼至少需要 2 个字。';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = '邮箱格式不正确。';
        }
        if (mb_strlen($body) < 2) {
            $errors[] = '留言内容至少需要 2 个字。';
        }

        $parentId = null;
        $rawParent = (string) ($_POST['parent_id'] ?? '');
        if ($rawParent !== '') {
            if (preg_match('/^[1-9][0-9]*$/', $rawParent) !== 1) {
                $errors[] = '回复目标无效。';
            } else {
                try {
                    $parent = (new CommentRepository($db))->findApproved((int) $rawParent, $post->id, true);
                } catch (Throwable) {
                    return ErrorPage::page($this->theme, $request, 503, '暂时无法读取留言，请稍后再试。', $this->version, $this->site());
                }
                if ($parent === null) {
                    $errors[] = '回复的留言不存在，或尚未通过审核。';
                } else {
                    $parentId = $parent->id;
                }
            }
        }

        if ($errors !== []) {
            return $this->error($request, $params, $errors, $this->values(), $parentId);
        }

        $ipHash = $this->ipHash();
        try {
            $comments = new CommentRepository($db);
            if ($comments->countRecentByIp($ipHash) >= 30) {
                return ErrorPage::page($this->theme, $request, 429, '留言太频繁，请稍后再试。', $this->version, $this->site());
            }
            if ($comments->duplicateExists($post->id, $ipHash, $body)) {
                return $this->error($request, $params, ['刚刚已经提交过相同留言，请勿重复提交。'], $this->values(), $parentId);
            }

            $commentId = $comments->create(
                $post->id,
                $parentId,
                $name,
                $email,
                $body,
                $ipHash,
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            );
            if ($commentId <= 0) {
                throw new \RuntimeException('Comment insert failed.');
            }
        } catch (Throwable) {
            return ErrorPage::page($this->theme, $request, 503, '留言暂时无法保存，请稍后再试。', $this->version, $this->site());
        }

        CommentForm::consume();
        try {
            (new AuditRepository($db))->append('comment.created', 'guest', [
                'id' => $commentId,
                'post_id' => $post->id,
                'post_slug' => $post->slug,
                'parent_id' => $parentId,
                'status' => 'pending',
            ]);
        } catch (Throwable) {
            // 审计失败不能阻断留言提交。
        }

        return Response::redirect('/posts/' . rawurlencode($post->slug) . '.html?comment=pending#comments', 303);
    }

    /** @param list<string> $errors
     *  @param array<string, string> $params
     *  @param array{author_name:string,author_email:string,body:string,email_enabled?:bool} $values
     */
    private function error(Request $request, array $params, array $errors, array $values, ?int $replyTo = null): Response
    {
        return (new PostController($this->version, $this->theme))->render($request, $params, [
            'errors' => $errors,
            'values' => $values,
            'reply_to' => $replyTo,
        ], 422);
    }

    /** @return array{author_name:string,author_email:string,body:string,email_enabled:bool} */
    private function values(): array
    {
        $emailEnabled = (string) ($_POST['notify_email'] ?? '') === '1';

        return [
            'author_name' => $this->text((string) ($_POST['author_name'] ?? ''), 40),
            'author_email' => $emailEnabled ? $this->text((string) ($_POST['author_email'] ?? ''), 190) : '',
            'body' => $this->text((string) ($_POST['body'] ?? ''), 1000),
            'email_enabled' => $emailEnabled,
        ];
    }

    private function text(string $value, int $limit): string
    {
        $value = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", $value)));

        return mb_substr($value, 0, $limit);
    }

    private function ipHash(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = (string) (Env::get('APP_KEY', 'cream-comments') ?? 'cream-comments');

        return hash_hmac('sha256', $ip, $key);
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
