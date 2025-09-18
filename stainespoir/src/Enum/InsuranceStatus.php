<?php
namespace App\Enum;

enum InsuranceStatus: string
{
    case PENDING  = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::APPROVED => 'Validée',
            self::REJECTED => 'Refusée',
        };
    }
}
