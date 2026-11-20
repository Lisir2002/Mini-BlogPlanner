<?php

declare(strict_types=1);

namespace Plugin\Admin;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * 把后台正文收敛到一组可安全直出的 HTML 标签与属性。
 *
 * 不在白名单内的普通标签会被展开保留文字，脚本、样式、表单和内嵌页面会被整段移除。
 * 链接和图片只接受 http、https、mailto 以及站内相对地址。
 */
final class HtmlSanitizer
{
    public function __construct(private readonly bool $allowH1 = false) {}

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'strong' => [],
        'em' => [],
        'del' => [],
        'code' => ['class'],
        'pre' => [],
        'blockquote' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'a' => ['href'],
        'img' => ['src', 'alt', 'title'],
        'figure' => [],
        'figcaption' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan', 'align'],
        'td' => ['colspan', 'rowspan', 'align'],
        'caption' => [],
        'sup' => [],
        'sub' => [],
    ];

    /** @var list<string> */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math',
    ];

    /** @return array<string, list<string>> */
    private function allowed(): array
    {
        $allowed = self::ALLOWED;
        if ($this->allowH1) {
            $allowed['h1'] = [];
        }

        return $allowed;
    }

    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="sanitize-root">'
            . $html
            . '</div></body></html>',
            LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return '';
        }

        $root = $document->getElementById('sanitize-root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->cleanChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if ($serialized !== false) {
                $output .= $serialized;
            }
        }

        return trim($output);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        $allowed = $this->allowed();
        $child = $parent->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP, true)) {
                    $parent->removeChild($child);
                } elseif (!array_key_exists($tag, $allowed)) {
                    $this->cleanChildren($child);
                    $this->unwrap($child);
                } else {
                    $this->cleanAttributes($child, $allowed[$tag]);
                    $this->cleanChildren($child);
                }
            }

            $child = $next;
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /** @param list<string> $allowed */
    private function cleanAttributes(DOMElement $element, array $allowed): void
    {
        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowed, true)) {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $value = trim($attribute->nodeValue ?? '');
                if (!$this->isSafeUrl($value, $name === 'src')) {
                    $remove[] = $attribute->nodeName;
                } else {
                    $element->setAttribute($name, $value);
                }
            }

            if ($name === 'class') {
                $value = trim($attribute->nodeValue ?? '');
                if (strtolower($element->tagName) !== 'code' || preg_match('/^language-[a-z0-9+#._-]+$/', $value) !== 1) {
                    $remove[] = $attribute->nodeName;
                }
            }

            if ($name === 'align') {
                $value = strtolower(trim($attribute->nodeValue ?? ''));
                if (!in_array($value, ['left', 'center', 'right'], true)) {
                    $remove[] = $attribute->nodeName;
                }
            }

            if ($name === 'colspan' || $name === 'rowspan') {
                $value = trim($attribute->nodeValue ?? '');
                if (preg_match('/^[1-9][0-9]?$/', $value) !== 1) {
                    $remove[] = $attribute->nodeName;
                }
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isSafeUrl(string $url, bool $image): bool
    {
        if ($url === '' || str_contains($url, "\n") || str_contains($url, "\r")) {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        if (!$image && str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($image) {
            return in_array($scheme, ['http', 'https'], true);
        }

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
