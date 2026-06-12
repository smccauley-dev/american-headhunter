<?php

namespace App\Filament\Admin\Resources\Properties\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\Properties\PropertyResource;
use App\Filament\Admin\Resources\Properties\Schemas\PropertyFormV2;
use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyManager;
use App\Models\Property\PropertyPhoto;
use App\Services\Identity\UserService;
use App\Services\Property\PropertyService;
use App\Support\AdminAuth;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    // ── Photo gallery actions (mounted from the photo-grid partial) ────────────

    public function editPropertyPhotoAction(): Action
    {
        return Action::make('editPropertyPhoto')
            ->modalHeading('Edit Photo Details')
            ->fillForm(function (array $arguments): array {
                $photo = PropertyPhoto::whereNull('deleted_at')->find($arguments['photoId'] ?? null);
                return [
                    'caption'    => $photo?->caption ?? '',
                    'tags'       => $photo?->tags ?? [],
                    'latitude'   => $photo?->latitude,
                    'longitude'  => $photo?->longitude,
                    'is_primary' => (bool) $photo?->is_primary,
                ];
            })
            ->form(function (array $arguments): array {
                $isPrimary = (bool) PropertyPhoto::whereNull('deleted_at')
                    ->find($arguments['photoId'] ?? null)?->is_primary;

                return [
                    Textarea::make('caption')
                        ->label('Caption / Description')
                        ->rows(3)
                        ->maxLength(255),
                    TagsInput::make('tags')
                        ->label('Tags')
                        ->suggestions(PropertyFormV2::photoTagSuggestions())
                        ->helperText('Press Enter after each tag. Used for gallery filtering.'),
                    TextInput::make('latitude')
                        ->label('Latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->placeholder('30.267153')
                        ->helperText('Where the photo was taken (WGS84). Auto-filled from the photo\'s EXIF GPS data when available.'),
                    TextInput::make('longitude')
                        ->label('Longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->placeholder('-97.743057')
                        ->helperText('Negative values are West.'),
                    Toggle::make('is_primary')
                        ->label('Primary (cover) photo')
                        ->disabled($isPrimary)
                        ->helperText($isPrimary
                            ? 'This is the current primary photo. Set another photo as primary to change it.'
                            : 'Make this the cover photo shown on the public listing.'),
                ];
            })
            ->action(function (array $arguments, array $data): void {
                abort_unless(AdminAuth::canManageProperties(), 403);

                $service = app(PropertyService::class);

                $service->updatePhotoDetails(
                    $arguments['photoId'],
                    $data['caption'] ?? null,
                    $data['tags'] ?? [],
                    filled($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
                    filled($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
                );

                // Disabled (already-primary) toggles don't dehydrate, so this
                // only fires when a non-primary photo was promoted.
                $madePrimary = ! empty($data['is_primary']);
                if ($madePrimary) {
                    $service->setPrimaryPhoto($arguments['photoId']);
                }

                Notification::make()
                    ->title($madePrimary ? 'Photo updated — set as primary' : 'Photo updated')
                    ->success()
                    ->send();
            });
    }

    public function movePropertyPhotoAction(): Action
    {
        return Action::make('movePropertyPhoto')
            ->action(function (array $arguments): void {
                abort_unless(AdminAuth::canManageProperties(), 403);
                app(PropertyService::class)->movePhoto($arguments['photoId'], $arguments['direction'] ?? 'up');
            });
    }

    public function deletePropertyPhotoAction(): Action
    {
        return Action::make('deletePropertyPhoto')
            ->requiresConfirmation()
            ->modalHeading('Delete photo?')
            ->modalDescription('The photo is removed from the gallery immediately. The file is retained for 30 days before being permanently purged.')
            ->modalSubmitActionLabel('Delete')
            ->color('danger')
            ->action(function (array $arguments): void {
                abort_unless(AdminAuth::canManageProperties(), 403);
                app(PropertyService::class)->deletePhoto($arguments['photoId']);
                Notification::make()->title('Photo deleted')->success()->send();
            });
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
