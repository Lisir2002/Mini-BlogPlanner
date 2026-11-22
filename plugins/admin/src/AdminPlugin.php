<?php

declare(strict_types=1);

namespace Plugin\Admin;

use Core\Config\Env;
use Core\Http\Request;
use Core\Http\Response;
use Core\Plugin\PluginInterface;
use Core\Routing\Route;
use Core\Theme\Theme;
use Core\Version\Version;

final class AdminPlugin implements PluginInterface
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
        return 'admin';
    }

    public function priority(): int
    {
        return 10;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $root = dirname(__DIR__, 3);
        $login = new LoginController($root, $this->theme);
        $posts = new PostAdminController($this->theme, $this->version);
        $revisions = new RevisionAdminController($this->theme);
        $media = new MediaAdminController($root, $this->theme);
        $audit = new AuditAdminController($this->theme);
        $tags = new TagAdminController($this->theme);
        $comments = new CommentAdminController($this->theme);
        $updates = new UpdatesAdminController($this->theme, $this->version, $root);
        $tokens = new ApiTokenAdminController($this->theme);
        $apiDocs = new ApiDocsAdminController($this->theme, $root);

        return [
            new Route(['GET'], '/admin', static fn (Request $request, array $params): Response => Response::redirect('/admin/posts.html', 302), 'admin.home'),
            new Route(['GET'], '/admin/login', static fn (Request $request, array $params): Response => $login->show($request), 'admin.login.show'),
            new Route(['POST'], '/admin/login', static fn (Request $request, array $params): Response => $login->login($request), 'admin.login.post'),
            new Route(['GET'], '/admin/captcha', static fn (Request $request, array $params): Response => $login->captcha($request), 'admin.captcha'),
            new Route(['POST'], '/admin/logout', static fn (Request $request, array $params): Response => $login->logout($request), 'admin.logout'),
            new Route(['GET'], '/admin/posts', static fn (Request $request, array $params): Response => $posts->index($request), 'admin.posts.index'),
            new Route(['POST'], '/admin/posts', static fn (Request $request, array $params): Response => $posts->create($request), 'admin.posts.create'),
            new Route(['GET'], '/admin/posts/new', static fn (Request $request, array $params): Response => $posts->createForm($request), 'admin.posts.new'),
            new Route(['POST'], '/admin/posts/bulk', static fn (Request $request, array $params): Response => $posts->bulk($request), 'admin.posts.bulk'),
            new Route(['GET'], '/admin/posts/{id}', static fn (Request $request, array $params): Response => $posts->editForm($request, $params), 'admin.posts.edit'),
            new Route(['GET'], '/admin/posts/{id}/revisions', static fn (Request $request, array $params): Response => $revisions->show($request, $params), 'admin.posts.revisions'),
            new Route(['POST'], '/admin/posts/{id}/revisions/{version}/restore', static fn (Request $request, array $params): Response => $revisions->restore($request, $params), 'admin.posts.revisions.restore'),
            new Route(['POST'], '/admin/posts/preview-body', static fn (Request $request, array $params): Response => $posts->previewBody($request), 'admin.posts.preview_body'),
            new Route(['GET'], '/admin/posts/{id}/preview', static fn (Request $request, array $params): Response => $posts->preview($request, $params), 'admin.posts.preview'),
            new Route(['POST'], '/admin/posts/{id}/draft', static fn (Request $request, array $params): Response => $posts->saveDraft($request, $params), 'admin.posts.draft.save'),
            new Route(['POST'], '/admin/posts/{id}/draft/discard', static fn (Request $request, array $params): Response => $posts->discardDraft($request, $params), 'admin.posts.draft.discard'),
            new Route(['POST'], '/admin/posts/{id}/publish', static fn (Request $request, array $params): Response => $posts->publish($request, $params), 'admin.posts.publish'),
            new Route(['POST'], '/admin/posts/{id}', static fn (Request $request, array $params): Response => $posts->update($request, $params), 'admin.posts.update'),
            new Route(['POST'], '/admin/posts/{id}/archive', static fn (Request $request, array $params): Response => $posts->archive($request, $params), 'admin.posts.archive'),
            new Route(['POST'], '/admin/posts/{id}/restore', static fn (Request $request, array $params): Response => $posts->restore($request, $params), 'admin.posts.restore'),
            new Route(['GET'], '/admin/media', static fn (Request $request, array $params): Response => $media->index($request), 'admin.media.index'),
            new Route(['POST'], '/admin/media', static fn (Request $request, array $params): Response => $media->upload($request), 'admin.media.upload'),
            new Route(['POST'], '/admin/media/{id}/delete', static fn (Request $request, array $params): Response => $media->delete($request, $params), 'admin.media.delete'),
            new Route(['GET'], '/admin/audit', static fn (Request $request, array $params): Response => $audit->index($request), 'admin.audit.index'),
            new Route(['GET'], '/admin/audit/export.csv', static fn (Request $request, array $params): Response => $audit->export($request), 'admin.audit.export'),
            new Route(['GET'], '/admin/comments', static fn (Request $request, array $params): Response => $comments->index($request), 'admin.comments.index'),
            new Route(['POST'], '/admin/comments/{id}/approve', static fn (Request $request, array $params): Response => $comments->approve($request, $params), 'admin.comments.approve'),
            new Route(['POST'], '/admin/comments/{id}/spam', static fn (Request $request, array $params): Response => $comments->spam($request, $params), 'admin.comments.spam'),
            new Route(['POST'], '/admin/comments/{id}/delete', static fn (Request $request, array $params): Response => $comments->delete($request, $params), 'admin.comments.delete'),
            new Route(['GET'], '/admin/tokens', static fn (Request $request, array $params): Response => $tokens->index($request), 'admin.tokens.index'),
            new Route(['GET'], '/admin/tokens/page/{page}', static fn (Request $request, array $params): Response => $tokens->index($request), 'admin.tokens.page'),
            new Route(['POST'], '/admin/tokens', static fn (Request $request, array $params): Response => $tokens->create($request), 'admin.tokens.create'),
            new Route(['POST'], '/admin/tokens/{id}/revoke', static fn (Request $request, array $params): Response => $tokens->revoke($request, $params), 'admin.tokens.revoke'),
            new Route(['GET'], '/admin/api-docs', static fn (Request $request, array $params): Response => $apiDocs->page($request), 'admin.api_docs.page'),
            new Route(['GET'], '/admin/api-docs/openapi.yaml', static fn (Request $request, array $params): Response => $apiDocs->openapi($request), 'admin.api_docs.openapi'),
            new Route(['GET'], '/admin/updates', static fn (Request $request, array $params): Response => $updates->index($request), 'admin.updates.index'),
            new Route(['GET'], '/admin/updates/status', static fn (Request $request, array $params): Response => $updates->status($request), 'admin.updates.status'),
            new Route(['POST'], '/admin/updates/apply', static fn (Request $request, array $params): Response => $updates->apply($request), 'admin.updates.apply'),
            new Route(['GET'], '/admin/tags', static fn (Request $request, array $params): Response => $tags->index($request), 'admin.tags.index'),
            new Route(['POST'], '/admin/tags', static fn (Request $request, array $params): Response => $tags->create($request), 'admin.tags.create'),
            new Route(['POST'], '/admin/tags/cleanup-empty', static fn (Request $request, array $params): Response => $tags->cleanup($request), 'admin.tags.cleanup'),
            new Route(['POST'], '/admin/tags/{id}/rename', static fn (Request $request, array $params): Response => $tags->rename($request, $params), 'admin.tags.rename'),
            new Route(['POST'], '/admin/tags/{id}/merge', static fn (Request $request, array $params): Response => $tags->merge($request, $params), 'admin.tags.merge'),
            new Route(['POST'], '/admin/tags/{id}/delete', static fn (Request $request, array $params): Response => $tags->delete($request, $params), 'admin.tags.delete'),
        ];
    }
}
