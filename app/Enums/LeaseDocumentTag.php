<?php

namespace App\Enums;

enum LeaseDocumentTag: string
{
    case Mla               = 'mla';
    case FullyExecuted     = 'fully_executed';
    case Amendment         = 'amendment';
    case Addendum          = 'addendum';
    case InsuranceCert     = 'insurance_certificate';
    case PropertyMap       = 'property_map';
    case HuntingRules      = 'hunting_rules';
    case Other             = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mla           => 'Master Lease Agreement',
            self::FullyExecuted => 'Fully Executed Contract',
            self::Amendment     => 'Amendment',
            self::Addendum      => 'Addendum',
            self::InsuranceCert => 'Insurance Certificate',
            self::PropertyMap   => 'Property Map / Boundary Survey',
            self::HuntingRules  => 'Hunting Rules & Regulations',
            self::Other         => 'Other',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Mla           => 'background:#fef3c7;color:#92400e;',
            self::FullyExecuted => 'background:#d1fae5;color:#065f46;',
            self::Amendment     => 'background:#ede9fe;color:#5b21b6;',
            self::Addendum      => 'background:#e0f2fe;color:#075985;',
            self::InsuranceCert => 'background:#fce7f3;color:#9d174d;',
            self::PropertyMap   => 'background:#f0fdf4;color:#166534;',
            self::HuntingRules  => 'background:#fff7ed;color:#9a3412;',
            self::Other         => 'background:#f3f4f6;color:#374151;',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
