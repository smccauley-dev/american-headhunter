<?php

namespace App\Filament\Admin\Pages;

use App\Services\Billing\SecurityDepositService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Read-only oversight of security-deposit forfeitures (Phase 5.x).
 *
 * Surfaces the landowner-abuse signal: a landowner who forfeits an abnormal share
 * of their concluded deposits is flagged for an admin to review — frequency is the
 * scam tell, independent of the stated reason. A second table ranks hunters by how
 * often a deposit has been forfeited against them (with the Trust Score outcome).
 * No automatic penalties here — flags are for human review (per product decision).
 */
class ForfeitureOversight extends Page
{
    use HasIconPageHeading;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';
    protected static ?string $title = 'Forfeiture Oversight';
    protected static ?int $navigationSort = 4;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Forfeiture Oversight', 'heroicon-o-flag');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canViewBilling();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.forfeiture-oversight';
    }

    /** @return array<int,array<string,mixed>> */
    public function landowners(): array
    {
        return app(SecurityDepositService::class)->landownerForfeitureStats();
    }

    /** @return array<int,array<string,mixed>> */
    public function hunters(): array
    {
        return app(SecurityDepositService::class)->hunterForfeitureStats();
    }

    public function flagThresholdLabel(): string
    {
        return SecurityDepositService::REVIEW_MIN_FORFEITS
            .'+ forfeitures and a rate at or above '
            .(int) round(SecurityDepositService::REVIEW_FLAG_RATE * 100).'%';
    }
}
