<?php

declare(strict_types=1);

namespace Core\Content;

use RuntimeException;

/**
 * 媒体上传失败的可分类错误。
 *
 * 调用方根据 reason 区分输入错误和存储故障，避免把服务器故障
 * 说成“文件格式不对”。
 */
final class MediaUploadException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isStorageFailure(): bool
    {
        return in_array($this->reason, ['storage', 'database'], true);
    }
}
