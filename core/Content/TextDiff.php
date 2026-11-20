<?php

declare(strict_types=1);

namespace Core\Content;

final class TextDiff
{
    private const MATRIX_LINE_LIMIT = 400;
    private const MATRIX_CHAR_LIMIT = 200_000;

    /**
     * @return list<array{type:string,text:string}>
     */
    public static function lines(string $old, string $new, int $maxRows = 800): array
    {
        if ($old === $new) {
            return [];
        }
        $oldLines = self::split($old);
        $newLines = self::split($new);
        $rowCount = count($oldLines);
        $newCount = count($newLines);

        if ($rowCount > self::MATRIX_LINE_LIMIT || $newCount > self::MATRIX_LINE_LIMIT
            || strlen($old) + strlen($new) > self::MATRIX_CHAR_LIMIT) {
            return self::blockDiff($oldLines, $newLines, $maxRows);
        }

        $matrix = array_fill(0, $rowCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = $rowCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $matrix[$i][$j] = $oldLines[$i] === $newLines[$j]
                    ? $matrix[$i + 1][$j + 1] + 1
                    : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;
        while ($i < $rowCount && $j < $newCount) {
            if ($oldLines[$i] === $newLines[$j]) {
                $rows[] = ['type' => 'same', 'text' => $oldLines[$i]];
                $i++;
                $j++;
            } elseif ($matrix[$i + 1][$j] >= $matrix[$i][$j + 1]) {
                $rows[] = ['type' => 'remove', 'text' => $oldLines[$i]];
                $i++;
            } else {
                $rows[] = ['type' => 'add', 'text' => $newLines[$j]];
                $j++;
            }
        }
        while ($i < $rowCount) {
            $rows[] = ['type' => 'remove', 'text' => $oldLines[$i]];
            $i++;
        }
        while ($j < $newCount) {
            $rows[] = ['type' => 'add', 'text' => $newLines[$j]];
            $j++;
        }

        return self::limit($rows, $maxRows);
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /**
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @return list<array{type:string,text:string}>
     */
    private static function blockDiff(array $oldLines, array $newLines, int $maxRows): array
    {
        $prefix = 0;
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        while ($prefix < $oldCount && $prefix < $newCount && $oldLines[$prefix] === $newLines[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < $oldCount - $prefix
            && $suffix < $newCount - $prefix
            && $oldLines[$oldCount - 1 - $suffix] === $newLines[$newCount - 1 - $suffix]
        ) {
            $suffix++;
        }

        $rows = [];
        for ($i = 0; $i < $prefix; $i++) {
            $rows[] = ['type' => 'same', 'text' => $oldLines[$i]];
        }
        for ($i = $prefix; $i < $oldCount - $suffix; $i++) {
            $rows[] = ['type' => 'remove', 'text' => $oldLines[$i]];
        }
        for ($i = $prefix; $i < $newCount - $suffix; $i++) {
            $rows[] = ['type' => 'add', 'text' => $newLines[$i]];
        }
        for ($i = $oldCount - $suffix; $i < $oldCount; $i++) {
            $rows[] = ['type' => 'same', 'text' => $oldLines[$i]];
        }

        return self::limit($rows, $maxRows);
    }

    /**
     * @param list<array{type:string,text:string}> $rows
     * @return list<array{type:string,text:string}>
     */
    private static function limit(array $rows, int $maxRows): array
    {
        if (count($rows) <= $maxRows) {
            return $rows;
        }

        $limited = array_slice($rows, 0, max(1, $maxRows - 1));
        $limited[] = ['type' => 'info', 'text' => '差异较长，只显示前 ' . max(1, $maxRows - 1) . ' 行。'];

        return $limited;
    }
}
