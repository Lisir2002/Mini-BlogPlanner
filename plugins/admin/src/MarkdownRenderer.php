<?php

declare(strict_types=1);

namespace Plugin\Admin;

/**
 * 把 Markdown 源文转换成可交给清洗器处理的 HTML 片段。
 *
 * 支持常用 CommonMark 语法和必要的 GFM 子集：标题、段落、强调、删除线、
 * 链接、图片、引用、列表、任务列表、代码块与表格。原始 HTML 默认转义为文本，
 * 由独立的 HTML 模式负责编辑标签。
 */
final class MarkdownRenderer
{
    public function __construct(
        private readonly int $minHeadingLevel = 2,
        private readonly int $maxHeadingLevel = 4,
    ) {
    }

    /** @var list<string> */
    private array $warnings = [];

    /** @return array{html:string,warnings:list<string>} */
    public function render(string $markdown): array
    {
        $this->warnings = [];
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = str_replace("\t", '    ', $markdown);
        $html = $this->blocks(explode("\n", $markdown));

        return [
            'html' => trim($html),
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /** @param list<string> $lines */
    private function blocks(array $lines): string
    {
        $out = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([A-Za-z0-9+#._-]*)\s*$/', $line, $match) === 1) {
                $fence = $match[1];
                $language = strtolower($match[2]);
                $i++;
                $code = [];
                $closed = false;
                while ($i < $count) {
                    $current = $lines[$i];
                    if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $current) === 1) {
                        $closed = true;
                        $i++;
                        break;
                    }
                    $code[] = $current;
                    $i++;
                }
                if (!$closed) {
                    $this->warnings[] = '代码块没有闭合围栏，已按当前内容渲染。';
                }
                $class = $language !== '' && preg_match('/^[a-z0-9+#._-]+$/', $language) === 1
                    ? ' class="language-' . $language . '"'
                    : '';
                $out[] = '<pre><code' . $class . '>' . $this->escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})[ \t]+(.*)$/', $line, $match) === 1) {
                $level = max($this->minHeadingLevel, min($this->maxHeadingLevel, strlen($match[1])));
                $out[] = '<h' . $level . '>' . $this->inline(trim($match[2])) . '</h' . $level . '>';
                $i++;
                continue;
            }

            if (preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $quote = [];
                while ($i < $count && preg_match('/^ {0,3}>/', $lines[$i]) === 1) {
                    $quote[] = preg_replace('/^ {0,3}> ?/', '', $lines[$i]) ?? '';
                    $i++;
                }
                $out[] = '<blockquote>' . $this->blocks($quote) . '</blockquote>';
                continue;
            }

            if ($this->isListStart($line)) {
                [$html, $next] = $this->listBlock($lines, $i);
                $out[] = $html;
                $i = $next;
                continue;
            }

            if ($this->isTableStart($lines, $i)) {
                [$html, $next] = $this->tableBlock($lines, $i);
                $out[] = $html;
                $i = $next;
                continue;
            }

            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && !$this->isBlockStart($lines, $i)) {
                $paragraph[] = $lines[$i];
                $i++;
            }
            if ($paragraph === []) {
                $paragraph[] = $line;
                $i++;
            }
            $out[] = '<p>' . $this->inline($this->joinParagraphLines($paragraph)) . '</p>';
        }

        return implode("\n", $out);
    }

    /** @param list<string> $lines */
    private function isBlockStart(array $lines, int $index): bool
    {
        $line = $lines[$index] ?? '';
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1
            || preg_match('/^ {0,3}#{1,6}[ \t]+/', $line) === 1
            || preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1
            || preg_match('/^ {0,3}>/', $line) === 1
            || $this->isListStart($line)
        ) {
            return true;
        }

        return $this->isTableStart($lines, $index);
    }

    private function isListStart(string $line): bool
    {
        return preg_match('/^ {0,3}(?:[-+*]|\d+[.)])[ \t]+/', $line) === 1;
    }

    /** @param list<string> $lines
     *  @return array{0:string,1:int}
     */
    private function listBlock(array $lines, int $start): array
    {
        $ordered = preg_match('/^ {0,3}\d+[.)][ \t]+/', $lines[$start]) === 1;
        $items = [];
        $count = count($lines);
        $i = $start;

        while ($i < $count) {
            $line = $lines[$i];
            $pattern = $ordered ? '/^ {0,3}\d+[.)][ \t]+(.*)$/' : '/^ {0,3}[-+*][ \t]+(.*)$/';
            if (preg_match($pattern, $line, $match) !== 1) {
                break;
            }
            $content = [$match[1]];
            $i++;

            while ($i < $count) {
                $next = $lines[$i];
                if (trim($next) === '') {
                    $peek = $i + 1;
                    if ($peek < $count && preg_match('/^(?: {2,}|\t)/', $lines[$peek]) === 1) {
                        $content[] = '';
                        $i++;
                        continue;
                    }
                    break;
                }
                if (preg_match($pattern, $next) === 1) {
                    break;
                }
                if (preg_match('/^ {2,}(.*)$/', $next, $indented) === 1) {
                    $content[] = $indented[1];
                    $i++;
                    continue;
                }
                break;
            }

            $items[] = $this->listItem($content);
        }

        $tag = $ordered ? 'ol' : 'ul';

        return ['<' . $tag . '>' . implode('', $items) . '</' . $tag . '>', $i];
    }

    /** @param list<string> $content */
    private function listItem(array $content): string
    {
        $text = $this->joinParagraphLines($content);
        if (preg_match('/^\[([ xX])\][ \t]+(.*)$/s', $text, $match) === 1) {
            $checked = strtolower($match[1]) === 'x';
            $text = ($checked ? '☑ ' : '☐ ') . $match[2];
        }

        return '<li>' . $this->inline($text) . '</li>';
    }

    /** @param list<string> $lines */
    private function isTableStart(array $lines, int $index): bool
    {
        $header = $lines[$index] ?? '';
        $separator = $lines[$index + 1] ?? '';
        if (!str_contains($header, '|') || preg_match('/^ {0,3}\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*$/', $separator) !== 1) {
            return false;
        }

        return count($this->splitRow($header)) === count($this->splitRow($separator));
    }

    /** @param list<string> $lines
     *  @return array{0:string,1:int}
     */
    private function tableBlock(array $lines, int $start): array
    {
        $header = $this->splitRow($lines[$start]);
        $separator = $this->splitRow($lines[$start + 1]);
        $alignments = [];
        foreach ($separator as $cell) {
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $alignments[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }

        $rows = [];
        $i = $start + 2;
        $count = count($lines);
        while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $cells = $this->splitRow($lines[$i]);
            if (count($cells) !== count($header)) {
                $this->warnings[] = '表格第 ' . ($i + 1) . ' 行的列数与表头不一致。';
            }
            $rows[] = $cells;
            $i++;
        }

        $head = '';
        foreach ($header as $index => $cell) {
            $align = $alignments[$index] ?? '';
            $head .= '<th' . ($align !== '' ? ' align="' . $align . '"' : '') . '>' . $this->inline($cell) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($header as $index => $cell) {
                $value = $row[$index] ?? '';
                $align = $alignments[$index] ?? '';
                $body .= '<td' . ($align !== '' ? ' align="' . $align . '"' : '') . '>' . $this->inline($value) . '</td>';
            }
            $body .= '</tr>';
        }

        return ['<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>', $i];
    }

    /** @return list<string> */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }
        $cells = explode('|', str_replace('\\|', '|', $line));

        return array_map(static fn (string $cell): string => trim($cell), $cells);
    }

    /** @param list<string> $lines */
    private function joinParagraphLines(array $lines): string
    {
        $joined = '';
        $hardBreak = false;
        foreach ($lines as $index => $line) {
            $raw = rtrim($line);
            $break = str_ends_with($raw, '  ') || str_ends_with($raw, '\\');
            $text = trim($break ? rtrim($raw, " \\") : $raw);
            if ($index === 0) {
                $joined = $text;
            } elseif ($hardBreak) {
                $joined .= '@@BR@@' . $text;
            } else {
                $joined = $this->smartJoin($joined, $text);
            }
            $hardBreak = $break;
        }

        return $joined;
    }

    private function smartJoin(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        $leftChar = mb_substr($left, -1);
        $rightChar = mb_substr($right, 0, 1);
        if ($this->isCjkBoundary($leftChar) && $this->isCjkBoundary($rightChar)) {
            return $left . $right;
        }

        return $left . ' ' . $right;
    }

    private function isCjkBoundary(string $char): bool
    {
        return preg_match('/^[\p{Han}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]$/u', $char) === 1;
    }

    private function inline(string $text): string
    {
        $tokens = [];
        $text = $this->escape($text);

        $text = preg_replace_callback('/(`+)(.+?)\1/s', function (array $match) use (&$tokens): string {
            return $this->token($tokens, '<code>' . trim($match[2]) . '</code>');
        }, $text) ?? $text;

        $text = preg_replace_callback('/(?<!\!)\!\[([^\]]*)\]\(([^)\s]+)(?:\s+["\'](.*?)["\'])?\)/s', function (array $match) use (&$tokens): string {
            $url = $this->safeUrl($match[2], true);
            if ($url === null) {
                $this->warnings[] = '图片地址不受支持，已跳过：' . $match[2];
                return $this->token($tokens, $match[0]);
            }
            $alt = $match[1];
            $title = isset($match[3]) && $match[3] !== '' ? ' title="' . $this->attribute($match[3]) . '"' : '';

            return $this->token($tokens, '<img src="' . $this->attribute($url) . '" alt="' . $this->attribute($alt) . '"' . $title . '>');
        }, $text) ?? $text;

        $text = preg_replace_callback('/(?<!\!)\[([^\]]+)\]\(([^)\s]+)(?:\s+["\'](.*?)["\'])?\)/s', function (array $match) use (&$tokens): string {
            $url = $this->safeUrl($match[2], false);
            if ($url === null) {
                $this->warnings[] = '链接地址不受支持，已跳过：' . $match[2];
                return $this->token($tokens, $match[0]);
            }
            $title = isset($match[3]) && $match[3] !== '' ? ' title="' . $this->attribute($match[3]) . '"' : '';
            $label = $this->inline($match[1]);

            return $this->token($tokens, '<a href="' . $this->attribute($url) . '"' . $title . '>' . $label . '</a>');
        }, $text) ?? $text;

        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;

        $text = str_replace('@@BR@@', '<br>', $text);
        foreach ($tokens as $key => $html) {
            $text = str_replace($key, $html, $text);
        }

        return $text;
    }

    /** @param array<string,string> $tokens */
    private function token(array &$tokens, string $html): string
    {
        $key = '@@T' . count($tokens) . '@@';
        $tokens[$key] = $html;

        return $key;
    }

    private function safeUrl(string $url, bool $image): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || str_contains($url, "\n") || str_contains($url, "\r") || str_starts_with($url, '//')) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            $scheme = strtolower((string) strstr($url, ':', true));

            return in_array($scheme, $image ? ['http', 'https'] : ['http', 'https', 'mailto'], true) ? $url : null;
        }

        return str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../') || preg_match('/^[\w.-]+/', $url) === 1
            ? $url
            : null;
    }

    private function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
