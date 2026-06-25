<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\FirstResponderVerificationsTable;
use App\Filament\Admin\Widgets\VeteranVerificationsTable;
use App\Models\Identity\FirstResponderVerification;
use App\Models\Identity\VeteranVerification;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Pages\Page;

/**
 * One review surface for service-status verification. Replaces the two former
 * top-level nav items (Veteran / First Responder) with a single "User
 * Verification" entry whose body stacks both queues as sections — each a table
 * widget driven by the shared BuildsVerificationQueue builder.
 *
 * The nav badge sums the pending counts across both queues and is coloured so
 * it stays readable against the dark sidebar (the default primary badge was
 * near-black on near-black).
 */
class UserVerifications extends Page
{
    use HasIconPageHeading;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'User Verification';

    protected static ?string $navigationLabel = 'User Verification';

    protected static ?string $slug = 'user-verifications';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Users & Access';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageUsers();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('User Verification', 'heroicon-o-shield-check');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::pendingCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        // Gold reads clearly on the dark sidebar; the default primary badge is
        // ink-on-ink and effectively invisible — the bug the user flagged.
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending verifications awaiting review';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VeteranVerificationsTable::class,
            FirstResponderVerificationsTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1; // stack the two queues as full-width sections
    }

    private static function pendingCount(): int
    {
        return VeteranVerification::query()->where('status', 'pending')->count()
            + FirstResponderVerification::query()->where('status', 'pending')->count();
    }
}
