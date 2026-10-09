<?php

namespace Plugins\Yutiv\ProductImport\Enums;

enum ImportStatus: string
{
    case Preview = 'preview';
    case Invalid = 'invalid';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return __(match ($this) {
            self::Preview => '검증 완료',self::Invalid => '검증 오류',self::Queued => '대기',self::Processing => '등록 중',self::Succeeded => '성공',self::Failed => '실패'
        });
    }
}
