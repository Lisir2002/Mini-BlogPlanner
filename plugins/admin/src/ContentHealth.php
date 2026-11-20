<?php

declare(strict_types=1);

namespace Plugin\Admin;

final class ContentHealth
{
    /**
     * @param array<string, mixed> $state
     * @param list<string> $warnings
     * @param list<array{level?:int,text?:string}> $headings
     * @return array{checks:list<array{id:string,label:string,level:string,ok:bool,blocking:bool,message:string}>,blockers:list<string>,warnings:list<string>}
     */
    public function inspect(array $state, array $warnings, array $headings): array
    {
        $title = trim((string) ($state['title'] ?? ''));
        $slug = trim((string) ($state['slug'] ?? ''));
        $excerpt = trim((string) ($state['excerpt'] ?? ''));
        $format = (string) ($state['body_format'] ?? 'markdown');
        $source = (string) ($state['body_source'] ?? '');
        $html = (string) ($state['body_html'] ?? '');
        $slugExists = (bool) ($state['slug_exists'] ?? false);
        $titleLength = mb_strlen($title);
        $excerptLength = mb_strlen($excerpt);

        $checks = [];
        $checks[] = $this->check(
            'title',
            '标题 4–60 字',
            'warning',
            $titleLength >= 4 && $titleLength <= 60,
            $titleLength === 0 ? '标题还没有填写。' : '标题当前 ' . $titleLength . ' 字，建议控制在 4–60 字。',
        );
        $checks[] = $this->check(
            'body',
            '正文有内容',
            'blocker',
            trim(strip_tags($html)) !== '',
            '正文不能为空，补一段再发布。',
        );
        $slugValid = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && mb_strlen($slug) <= 190;
        $checks[] = $this->check(
            'slug',
            '固定链接合法且唯一',
            'blocker',
            $slugValid && !$slugExists,
            $slugExists ? '固定链接已被其他文章使用。' : '固定链接只能使用小写字母、数字和单个短横线。',
        );
        [$headingOk, $headingMessage] = $this->headings($headings);
        $checks[] = $this->check('headings', '标题层级不跳级', 'warning', $headingOk, $headingMessage);
        [$altOk, $altMessage] = $this->imageAlts($html);
        $checks[] = $this->check('images', '图片有替代文字', 'warning', $altOk, $altMessage);
        $checks[] = $this->check(
            'excerpt',
            '摘要 40–120 字',
            'warning',
            $excerptLength >= 40 && $excerptLength <= 120,
            $excerptLength === 0 ? '摘要还没有填写。' : '摘要当前 ' . $excerptLength . ' 字，建议控制在 40–120 字。',
        );
        $fenceOk = $format !== 'markdown' || $this->fencesPaired($source);
        $checks[] = $this->check('fences', '代码围栏成对', 'blocker', $fenceOk, 'Markdown 代码围栏没有闭合，补上结尾的 ```。');
        $tableOk = true;
        foreach ($warnings as $warning) {
            if (str_contains($warning, '表格')) {
                $tableOk = false;
                break;
            }
        }
        $checks[] = $this->check('tables', '表格列数一致', 'warning', $tableOk, '表格分隔行与表头列数不一致。');
        $linkCount = $this->externalLinks($html);
        $checks[] = $this->check('links', '外链数量', 'info', true, $linkCount === 0 ? '正文没有外链。' : '正文包含 ' . $linkCount . ' 个外链，发布后请抽查。');
        $checks[] = $this->check('reading', '阅读时长', 'info', true, '阅读时长由正文字数自动计算。');

        $blockers = [];
        $messages = [];
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $messages[] = $check['message'];
                if ($check['blocking']) {
                    $blockers[] = $check['message'];
                }
            }
        }

        return ['checks' => $checks, 'blockers' => $blockers, 'warnings' => $messages];
    }

    /** @return array{id:string,label:string,level:string,ok:bool,blocking:bool,message:string} */
    private function check(string $id, string $label, string $level, bool $ok, string $message): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'level' => $level,
            'ok' => $ok,
            'blocking' => $level === 'blocker',
            'message' => $ok ? '' : $message,
        ];
    }

    /** @param list<array{level?:int,text?:string}> $headings
     *  @return array{0:bool,1:string}
     */
    private function headings(array $headings): array
    {
        $previous = 1;
        foreach ($headings as $heading) {
            $level = max(2, min(4, (int) ($heading['level'] ?? 2)));
            if ($previous === 1 && $level !== 2) {
                return [false, '正文第一个标题应从 H2 开始。'];
            }
            if ($level > $previous + 1) {
                return [false, '标题层级从 H' . $previous . ' 跳到了 H' . $level . '。'];
            }
            $previous = $level;
        }

        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function imageAlts(string $html): array
    {
        if (!str_contains($html, '<img')) {
            return [true, ''];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach ($document->getElementsByTagName('img') as $image) {
                if (trim($image->getAttribute('alt')) === '') {
                    return [false, '有图片缺少 alt 描述，补一句替代文字。'];
                }
            }
        } catch (\Throwable) {
            return [true, ''];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [true, ''];
    }

    private function externalLinks(string $html): int
    {
        if (!str_contains($html, '<a ')) {
            return 0;
        }

        $count = 0;
        if (preg_match_all('/<a\s[^>]*href="(https?:\/\/[^"]+)"/i', $html, $matches) === false) {
            return 0;
        }
        foreach ($matches[1] as $href) {
            if (parse_url($href, PHP_URL_HOST) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function fencesPaired(string $source): bool
    {
        $count = 0;
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $count++;
            }
        }

        return $count % 2 === 0;
    }
}
