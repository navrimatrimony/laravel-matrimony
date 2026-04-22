<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Pending = 'pending';

    public static function fromPayu(string $raw): self
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            'success' => self::Success,
            'pending' => self::Pending,
            default => self::Failed,
        };
    }
}
