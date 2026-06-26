<?php

namespace App\Filament\Admin\Resources\IncidentReports\Pages;

use App\Filament\Admin\Resources\IncidentReports\IncidentReportResource;
use App\Models\Incidents\IncidentReport;
use App\Services\Incidents\IncidentService;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewIncidentReport extends ViewRecord
{
    use HasIconPageHeading;

    protected static string $resource = IncidentReportResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Incident Report', 'heroicon-o-exclamation-triangle');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Begin triage — open → investigating.
            Action::make('investigate')
                ->label('Start Investigating')
                ->color('info')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (IncidentReport $record): bool => $record->status === IncidentService::STATUS_OPEN)
                ->modalHeading('Start Investigating')
                ->modalSubmitActionLabel('Mark Investigating')
                ->form([
                    Toggle::make('notified')->label('Authorities have been notified')->default(false),
                    TextInput::make('report_number')->label('Authority report # (optional)')->maxLength(100),
                ])
                ->action(fn (IncidentReport $record, array $data) => $this->applyStatus($record, IncidentService::STATUS_INVESTIGATING, [
                    'authorities_notified'    => (bool) ($data['notified'] ?? false),
                    'authority_report_number' => $data['report_number'] ?? null,
                ])),

            // Resolve — capture resolution notes.
            Action::make('resolve')
                ->label('Resolve')
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (IncidentReport $record): bool => in_array($record->status, [IncidentService::STATUS_OPEN, IncidentService::STATUS_INVESTIGATING], true))
                ->modalHeading('Resolve Incident')
                ->modalSubmitActionLabel('Mark Resolved')
                ->form([
                    Textarea::make('notes')->label('Resolution notes')->required()->maxLength(2000),
                ])
                ->action(fn (IncidentReport $record, array $data) => $this->applyStatus($record, IncidentService::STATUS_RESOLVED, [
                    'resolution_notes' => $data['notes'],
                ])),

            // Close — final state.
            Action::make('close')
                ->label('Close')
                ->color('gray')
                ->icon(Heroicon::OutlinedXCircle)
                ->visible(fn (IncidentReport $record): bool => in_array($record->status, [IncidentService::STATUS_OPEN, IncidentService::STATUS_INVESTIGATING, IncidentService::STATUS_RESOLVED], true))
                ->modalHeading('Close Incident')
                ->modalSubmitActionLabel('Close Incident')
                ->form([
                    Textarea::make('notes')->label('Closing notes (optional)')->maxLength(2000),
                ])
                ->action(fn (IncidentReport $record, array $data) => $this->applyStatus($record, IncidentService::STATUS_CLOSED, array_filter([
                    'resolution_notes' => $data['notes'] ?? null,
                ], fn ($v) => $v !== null))),
        ];
    }

    /** @param array<string,mixed> $extra */
    private function applyStatus(IncidentReport $record, string $status, array $extra = []): void
    {
        try {
            app(IncidentService::class)->updateStatus($record->id, $status, auth()->id(), $extra);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Update failed')->body($e->getMessage())->danger()->send();
            return;
        }

        Notification::make()->title('Incident updated')->success()->send();
        $this->redirect(IncidentReportResource::getUrl('view', ['record' => $record]));
    }
}
