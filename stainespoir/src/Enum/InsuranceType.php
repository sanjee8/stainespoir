<?php
namespace App\Enum;

enum InsuranceType: string
{
    case RC = 'RC';         // Responsabilité civile
    case HEALTH = 'HEALTH'; // Assurance maladie

    public function label(): string
    {
        return match ($this) {
            self::RC => 'Responsabilité civile',
            self::HEALTH => 'Assurance maladie',
        };
    }
}
