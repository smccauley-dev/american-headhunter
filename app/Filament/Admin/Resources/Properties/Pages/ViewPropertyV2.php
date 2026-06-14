<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasViewPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyInfolistV2;
use App\Mail\CheckInQrMail;
use App\Services\Documents\DocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class ViewPropertyV2 extends ViewRecord
{
    use HasViewPageScaffold;

    protected static string $resource = PropertyResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PropertyInfolistV2::configure($schema);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->standardViewHeaderActions(),
            // View the property's gate check-in QR and email it to anyone who
            // needs it — a staff backstop for when a hunter can't find it.
            Action::make('checkInQr')
                ->label('Check-In QR')
                ->icon(Heroicon::OutlinedQrCode)
                ->modalHeading('Property Check-In QR')
                ->modalDescription('Hunters scan this at the gate to log their arrival. The same code is reused across every lease on this property.')
                ->modalSubmitActionLabel('Send Email')
                ->form([
                    Placeholder::make('qr')
                        ->hiddenLabel()
                        ->content(fn () => new HtmlString(
                            '<img src="' . e(route('checkin.qr.png', $this->checkInQrToken()))
                            . '" width="200" height="200" alt="Check-in QR" style="display:block;margin:0 auto" />'
                        )),
                    TextInput::make('email')
                        ->label('Email QR to')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    Mail::to($data['email'])->queue(new CheckInQrMail(
                        recipientName: 'there',
                        propertyTitle: $this->record->title ?? 'the property',
                        scanUrl: route('checkin.scan', $this->checkInQrToken()),
                    ));
                })
                ->successNotificationTitle('Check-in QR emailed.'),
        ];
    }

    private function checkInQrToken(): string
    {
        return app(DocumentService::class)
            ->getOrCreateCheckInQrForProperty($this->record->id)
            ->token;
    }
}
