<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyFormV2;
use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyManager;
use App\Services\Identity\UserService;
use App\Support\AdminAuth;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditPropertyV2 extends EditRecord
{
    use HasEditPageScaffold;
    protected static string $resource = PropertyResource::class;

    private array $pendingAmenityIds = [];

    public function form(Schema $schema): Schema
    {
        return PropertyFormV2::configure($schema);
    }

    // Suppress the separate relation-manager tab bar below the form —
    // Species, Rules, and Listings are managed inline via form tabs above.
    public function getRelationManagers(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $ids = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'amenities_')) {
                foreach ((array) $value as $id) {
                    $ids[] = $id;
                }
                unset($data[$key]);
            }
        }
        // Validate IDs against the database — reject any that don't exist in property_amenities
        $ids = array_unique($ids);
        $this->pendingAmenityIds = PropertyAmenity::whereIn('id', $ids)->pluck('id')->toArray();
        return $data;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->amenities()->sync($this->pendingAmenityIds);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->standardHeaderActions(),

            Action::make('grant_manager')
                ->label('Grant Manager Access')
                ->icon('heroicon-o-user-plus')
                ->color('primary')
                ->visible(fn () => AdminAuth::canManageProperties() && $this->getRecord() !== null)
                ->form([
                    TextInput::make('user_email')
                        ->label('User Email')
                        ->email()
                        ->required()
                        ->placeholder('hunter@example.com'),
                    Select::make('role')
                        ->label('Role')
                        ->required()
                        ->options([
                            'owner'    => 'Owner',
                            'co_owner' => 'Co-Owner',
                            'manager'  => 'Manager',
                            'operator' => 'Operator',
                        ]),
                ])
                ->action(function (array $data): void {
                    $user = app(UserService::class)->findByEmail($data['user_email']);

                    if (! $user) {
                        Notification::make()
                            ->title('No user found with that email address.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $exists = PropertyManager::where('property_id', $this->getRecord()->id)
                        ->where('user_id', $user->id)
                        ->whereNull('revoked_at')
                        ->exists();

                    if ($exists) {
                        Notification::make()
                            ->title('This user already has active manager access.')
                            ->warning()
                            ->send();
                        return;
                    }

                    PropertyManager::create([
                        'property_id'        => $this->getRecord()->id,
                        'user_id'            => $user->id,
                        'role'               => $data['role'],
                        'granted_by_user_id' => auth()->id(),
                        'granted_at'         => now(),
                    ]);

                    Notification::make()
                        ->title('Manager access granted.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function revokePropertyManager(string $managerId): void
    {
        abort_unless(AdminAuth::canManageProperties(), 403);

        $manager = PropertyManager::where('property_id', $this->getRecord()->id)
            ->whereNull('revoked_at')
            ->find($managerId);

        if (! $manager) {
            Notification::make()
                ->title('Manager record not found or already revoked.')
                ->warning()
                ->send();
            return;
        }

        $manager->update(['revoked_at' => now()]);

        Notification::make()
            ->title('Manager access revoked.')
            ->success()
            ->send();
    }
}
