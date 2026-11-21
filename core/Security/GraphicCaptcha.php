<?php

declare(strict_types=1);

namespace Core\Security;

use RuntimeException;

/**
 * 站内图形验证码：答案只保存在会话里，图片由服务端随机绘制。
 * 每次验证无论成功或失败都会消耗当前题目，避免同一张图重复提交。
 */
final class GraphicCaptcha
{
    public const SESSION_KEY = 'admin_captcha';

    private const ALPHABET = '2346789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LENGTH = 5;
    private const WIDTH = 184;
    private const HEIGHT = 58;

    public function __construct(
        private readonly int $ttl = 300,
        private readonly int $maxAttempts = 5,
    ) {}

    public function issue(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        $salt = random_bytes(16);
        $_SESSION[self::SESSION_KEY] = [
            'hash' => hash_hmac('sha256', $code, $salt),
            'salt' => $salt,
            'expires' => time() + $this->ttl,
            'tries' => 0,
        ];

        return $code;
    }

    public function verify(?string $answer): bool
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state)) {
            return false;
        }

        $hash = $state['hash'] ?? null;
        $salt = $state['salt'] ?? null;
        $expires = $state['expires'] ?? null;
        $tries = $state['tries'] ?? null;
        if (!is_string($hash) || !is_string($salt) || !is_int($expires) || !is_int($tries)) {
            unset($_SESSION[self::SESSION_KEY]);

            return false;
        }

        if ($expires < time() || $tries >= $this->maxAttempts) {
            unset($_SESSION[self::SESSION_KEY]);

            return false;
        }

        $tries++;
        $expected = hash_hmac('sha256', self::normalize($answer), $salt);
        $ok = hash_equals($hash, $expected);

        if ($ok || $tries >= $this->maxAttempts) {
            unset($_SESSION[self::SESSION_KEY]);
        } else {
            $state['tries'] = $tries;
            $_SESSION[self::SESSION_KEY] = $state;
        }

        return $ok;
    }

    public function image(string $code): string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            throw new RuntimeException('图形验证码需要 PHP GD 与 FreeType 支持。');
        }

        $font = __DIR__ . '/Assets/DejaVuSans-Bold.ttf';
        if (!is_file($font)) {
            throw new RuntimeException('图形验证码字体缺失。');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new RuntimeException('无法创建验证码画布。');
        }
        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }

        $paper = $this->color($image, 246, 239, 222);
        $edge = $this->color($image, 217, 203, 178);
        /** @var array<int, array{int<0, 255>, int<0, 255>, int<0, 255>}> $ink */
        $ink = [[55, 48, 40], [73, 61, 49], [45, 66, 72], [91, 58, 46]];
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $paper);
        imagerectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $edge);

        for ($i = 0; $i < 9; $i++) {
            $color = $this->color(
                $image,
                random_int(190, 225),
                random_int(180, 215),
                random_int(150, 195),
                random_int(52, 88),
            );
            $x1 = random_int(-20, self::WIDTH);
            $y1 = random_int(0, self::HEIGHT);
            $x2 = random_int(-20, self::WIDTH);
            $y2 = random_int(0, self::HEIGHT);
            imageline($image, $x1, $y1, $x2, $y2, $color);
        }

        for ($i = 0; $i < 4; $i++) {
            $color = $this->color(
                $image,
                random_int(150, 220),
                random_int(120, 200),
                random_int(100, 185),
                random_int(70, 96),
            );
            $w = random_int(40, 120);
            $h = random_int(16, 44);
            imagearc(
                $image,
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                $w,
                $h,
                random_int(0, 360),
                random_int(0, 360),
                $color,
            );
        }

        foreach (str_split($code) as $index => $character) {
            $x = 17 + $index * 32 + random_int(-2, 2);
            $y = 40 + random_int(-4, 3);
            $fontSize = random_int(23, 27);
            $angle = random_int(-18, 18);
            $shadow = $this->color($image, 255, 249, 236, 38);
            $rgb = $ink[random_int(0, count($ink) - 1)];
            $text = $this->color($image, $rgb[0], $rgb[1], $rgb[2]);
            imagettftext($image, $fontSize, $angle, $x + 1, $y + 1, $shadow, $font, $character);
            imagettftext($image, $fontSize, $angle, $x, $y, $text, $font, $character);
        }

        for ($i = 0; $i < 22; $i++) {
            $color = $this->color(
                $image,
                random_int(160, 220),
                random_int(145, 205),
                random_int(120, 190),
                random_int(70, 105),
            );
            imagefilledellipse(
                $image,
                random_int(2, self::WIDTH - 2),
                random_int(2, self::HEIGHT - 2),
                random_int(1, 4),
                random_int(1, 4),
                $color,
            );
        }

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        if (!is_string($png) || $png === '') {
            throw new RuntimeException('验证码图片生成失败。');
        }

        return $png;
    }

    private static function normalize(?string $answer): string
    {
        $answer = strtoupper(trim((string) $answer));

        return (string) preg_replace('/[^A-Z0-9]/', '', $answer);
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     * @param int<0, 127> $alpha
     */
    private function color(\GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
        if ($color === false) {
            throw new RuntimeException('验证码颜色分配失败。');
        }

        return $color;
    }
}
