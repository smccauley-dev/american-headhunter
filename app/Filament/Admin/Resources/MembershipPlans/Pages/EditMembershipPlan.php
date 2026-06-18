<?php

namespace App\Filament\Admin\Resources\MembershipPlans\Pages;

use App\Filament\Admin\Concerns\ConvertsPlanPrices;
use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\MembershipPlans\MembershipPlanResource;
use App\Models\Platform\MembershipPlan;
use App\Services\Platform\PlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditMembershipPlan extends EditRecord
{
    use ConvertsPlanPrices;
    use HasEditPageScaffold;

    protected static string $resource = MembershipPlanResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->centsToDollars($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->dollarsToCents($data);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Plan saved — Publish a new version to apply pricing to new subscribers.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publishVersion')
                ->label('Publish New Version')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Publish New Plan Version')
                ->modalDescription('Snapshots the current pricing and entitlements into a new immutable version. New subscribers get this version; existing subscribers keep theirs.')
                ->form([
                    Textarea::make('reason')
                        ->label('Change Reason')
                        ->placeholder('e.g. Q3 2026 price increase')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    // Persist the current form (staged edits) before snapshotting.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    /** @var MembershipPlan $plan */
                    $plan = $this->getRecord();

                    $version = app(PlanService::class)->publishNewVersion(
                        $plan,
                        auth()->id(),
                        $data['reason'] ?? null,
                    );

                    Notification::make()
                        ->title("Published version {$version->version_number}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
