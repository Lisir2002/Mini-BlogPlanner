<?php

declare(strict_types=1);

namespace Plugin\Admin;

use DOMDocument;
use DOMElement;

/**
 * 统一生成编辑预览与保存所需的正文 HTML。
 *
 * Markdown 先解析再清洗，HTML 直接清洗；两条路径共用同一份白名单，
 * 保证预览和发布结果使用同一套规则。
 */
final class BodyRenderer
{
    public function __construct(
        private readonly MarkdownRenderer $markdown = new MarkdownRenderer(),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /** @return array{html:string,warnings:list<string>} */
    public function render(string $format, string $source): array
    {
        $warnings = [];
        if ($format === 'markdown') {
            $result = $this->markdown->render($source);
            $warnings = $result['warnings'];
            $html = $result['html'];
        } else {
            $html = $source;
            if (preg_match('/<(?:script|style|iframe|object|embed|form|input|button|textarea|select|svg|math)\b/i', $source) === 1
                || preg_match('/\son[a-z]+\s*=/i', $source) === 1
            ) {
                $warnings[] = '脚本、样式、表单和内嵌内容已被移除。';
            }
        }

        return [
            'html' => $this->sanitizer->clean($html),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return list<array{level:int,text:string}> */
    public function headings(string $html): array
    {
        $document = $this->document($html);
        if (!$document instanceof DOMDocument) {
            return [];
        }

        $headings = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (preg_match('/^h[2-4]$/', strtolower($element->tagName)) !== 1) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
            if ($text !== '') {
                $headings[] = ['level' => (int) substr($element->tagName, 1), 'text' => $text];
            }
        }

        return $headings;
    }

    /** @return array{chars:int,words:int,reading_minutes:int} */
    public function stats(string $html): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $chars = mb_strlen($text);
        $words = preg_match_all('/[\p{Han}]|[A-Za-z0-9]+/u', $text) ?: 0;

        return [
            'chars' => $chars,
            'words' => $words,
            'reading_minutes' => max(1, (int) ceil($chars / 500)),
        ];
    }

    private function document(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="body-root">'
            . $html
            . '</div></body></html>',
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $root = $document->getElementById('body-root');
        return $root instanceof DOMElement ? $document : null;
    }
}
