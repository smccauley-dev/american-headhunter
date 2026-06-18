<?php

namespace App\Filament\Admin\Resources\Properties\Schemas;

use App\Models\Property\PropertyAmenity;
use App\Models\Property\PropertyManager;
use App\Models\Property\PropertyPhoto;
use App\Services\Property\PropertyMapService;
use App\Services\Property\PropertyService;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class PropertyFormV2
{
    private static array $speciesOptions = [
        'whitetail_deer' => 'Whitetail Deer',
        'mule_deer'      => 'Mule Deer',
        'turkey'         => 'Turkey',
        'waterfowl'      => 'Waterfowl',
        'dove'           => 'Dove',
        'hog'            => 'Hog',
        'elk'            => 'Elk',
        'bear'           => 'Bear',
        'antelope'       => 'Antelope',
        'pheasant'       => 'Pheasant',
        'quail'          => 'Quail',
        'rabbit'         => 'Rabbit',
        'squirrel'       => 'Squirrel',
        'coyote'         => 'Coyote',
        'other'          => 'Other',
    ];

    /** Suggested photo tags — free-form entry is also allowed. */
    public static function photoTagSuggestions(): array
    {
        return [
            'aerial', 'habitat', 'food plot', 'stand', 'blind', 'trail camera',
            'water', 'creek', 'pond', 'access', 'road', 'gate', 'cabin',
            'lodging', 'harvest', 'wildlife', 'boundary', 'terrain',
        ];
    }

    private static function uploadPhotosAction(): Action
    {
        return Action::make('upload_photos')
            ->label('Upload Photos')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Upload Property Photos')
            ->form([
                FileUpload::make('photos')
                    ->label('Photos')
                    ->image()
                    ->multiple()
                    ->disk('local')
                    ->directory('pending-property-photos')
                    ->maxSize(10240)
                    ->maxFiles(20)
                    ->required()
                    ->helperText('JPG, PNG, or WebP — max 10 MB each, up to 20 per batch.'),
                TextInput::make('caption')
                    ->label('Caption')
                    ->maxLength(255)
                    ->helperText('Optional — applied to every photo in this batch. Edit photos individually afterwards.'),
                TagsInput::make('tags')
                    ->label('Tags')
                    ->suggestions(self::photoTagSuggestions())
                    ->helperText('Optional — applied to every photo in this batch. Press Enter after each tag.'),
                Toggle::make('import_exif')
                    ->label('Import photo metadata (EXIF)')
                    ->default(true)
                    ->helperText('When on, we read the metadata each camera or phone embeds in a photo — including any GPS coordinates recorded when the picture was taken — and use it to auto-fill the photo\'s location. Turn it off to ignore that metadata and leave the location blank. Imported coordinates stay private to staff and lessees; they are never shown publicly unless you separately enable that.'),
            ])
            ->action(function (array $data, $record): void {
                $uploaded = 0;
                foreach ((array) ($data['photos'] ?? []) as $path) {
                    try {
                        $file = new \Illuminate\Http\UploadedFile(
                            Storage::disk('local')->path($path),
                            basename($path),
                            Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
                            null,
                            true,
                        );
                        app(PropertyService::class)->addPhoto(
                            $record->id,
                            $file,
                            $data['caption'] ?? null,
                            $data['tags'] ?? [],
                            (bool) ($data['import_exif'] ?? true),
                        );
                        $uploaded++;
                    } catch (\Throwable $e) {
                        report($e);
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }

                if ($uploaded > 0) {
                    Notification::make()
                        ->title($uploaded === 1 ? 'Photo uploaded' : "{$uploaded} photos uploaded")
                        ->success()
                        ->send();
                } else {
                    Notification::make()->title('Upload failed')->danger()->send();
                }
            });
    }

    private static function renderPhotosHtml($record): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to manage photos.</p>'
            );
        }

        try {
            $photos = PropertyPhoto::where('property_id', $record->id)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            report($e);
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        if ($photos->isEmpty()) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">'
                . 'No photos yet. Use <strong>Upload Photos</strong> in the section header to add some.'
                . '</p>'
            );
        }

        return new HtmlString(view('filament.admin.properties.photo-grid', [
            'photos' => $photos,
        ])->render());
    }

    private static function uploadMapImagesAction(): Action
    {
        return Action::make('upload_map_images')
            ->label('Upload Map Images')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Upload Map Images')
            ->form([
                FileUpload::make('maps')
                    ->label('Map Images')
                    ->image()
                    ->multiple()
                    ->disk('local')
                    ->directory('pending-property-maps')
                    ->maxSize(15360)
                    ->maxFiles(10)
                    ->required()
                    ->helperText('JPG, PNG, or WebP — max 15 MB each. The first map image on a property becomes the boundary map.'),
                TextInput::make('description')
                    ->label('Description')
                    ->maxLength(255)
                    ->helperText('Optional — applied to every image in this batch. Edit individually afterwards.'),
                Toggle::make('import_exif')
                    ->label('Import image metadata (EXIF)')
                    ->default(true)
                    ->helperText('When on, we read the metadata each camera or phone embeds in a photo — including any GPS coordinates recorded when the picture was taken — and use it to auto-fill the photo\'s location. Turn it off to ignore that metadata and leave the location blank. Imported coordinates stay private to staff and lessees; they are never shown publicly unless you separately enable that.'),
            ])
            ->action(function (array $data, $record): void {
                $uploaded = 0;
                foreach ((array) ($data['maps'] ?? []) as $path) {
                    try {
                        $file = new \Illuminate\Http\UploadedFile(
                            Storage::disk('local')->path($path),
                            basename($path),
                            Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
                            null,
                            true,
                        );
                        app(PropertyMapService::class)->addMapImage(
                            $record->id,
                            $file,
                            $data['description'] ?? null,
                            (bool) ($data['import_exif'] ?? true),
                        );
                        $uploaded++;
                    } catch (\Throwable $e) {
                        report($e);
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }

                if ($uploaded > 0) {
                    Notification::make()
                        ->title($uploaded === 1 ? 'Map image uploaded' : "{$uploaded} map images uploaded")
                        ->success()
                        ->send();
                } else {
                    Notification::make()->title('Upload failed')->danger()->send();
                }
            });
    }

    private static function renderMapEditorHtml($record, ?string $selectedMapImageId): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to manage maps.</p>'
            );
        }

        try {
            $service = app(PropertyMapService::class);
            $images  = $service->getMapImages($record->id);
            $deleted = $service->getDeletedMapImages($record->id);

            $selected = ($selectedMapImageId ? $images->firstWhere('id', $selectedMapImageId) : null)
                ?? $images->first();
            $selected?->load('markers');
        } catch (\Throwable $e) {
            report($e);
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        return new HtmlString(view('filament.admin.properties.map-editor', [
            'images'   => $images,
            'selected' => $selected,
            'deleted'  => $deleted,
        ])->render());
    }

    private static function grantManagerAction(): Action
    {
        return Action::make('grant_manager')
            ->label('Grant Manager Access')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn ($record) => \App\Support\AdminAuth::canManageProperties() && $record !== null)
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
            ->action(function (array $data, $record): void {
                $user = app(\App\Services\Identity\UserService::class)->findByEmail($data['user_email']);

                if (! $user) {
                    Notification::make()
                        ->title('No user found with that email address.')
                        ->danger()
                        ->send();
                    return;
                }

                $exists = PropertyManager::where('property_id', $record->id)
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
                    'property_id'        => $record->id,
                    'user_id'            => $user->id,
                    'role'               => $data['role'],
                    'granted_by_user_id' => auth()->id(),
                    'granted_at'         => now(),
                ]);

                Notification::make()
                    ->title('Manager access granted.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Eligible managers for the "Add Manager Contact" picker: this property's active
     * managers (co_owner/manager/operator) who are not already field contacts.
     * Keyed by property_managers.id, labelled with the user's name and role.
     */
    private static function eligibleManagerContactOptions($record): array
    {
        if (! $record?->id) {
            return [];
        }

        $managers = PropertyManager::where('property_id', $record->id)
            ->whereNull('revoked_at')
            ->whereIn('role', ['co_owner', 'manager', 'operator'])
            ->where('is_field_contact', false)
            ->orderBy('granted_at')
            ->get();

        if ($managers->isEmpty()) {
            return [];
        }

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $managers->pluck('user_id')->all())
            ->get()
            ->keyBy('id');

        $roleLabels = ['co_owner' => 'Co-Owner', 'manager' => 'Property Manager', 'operator' => 'Operator'];

        return $managers->mapWithKeys(function (PropertyManager $m) use ($users, $roleLabels) {
            $user = $users->get($m->user_id);
            $name = $user?->profile?->full_name ?: $user?->email ?: $m->user_id;
            $role = $roleLabels[$m->role] ?? ucfirst($m->role);

            return [$m->id => "{$name} ({$role})"];
        })->all();
    }

    private static function addManagerContactAction(): Action
    {
        return Action::make('add_manager_contact')
            ->label('Add Manager Contact')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn ($record) => \App\Support\AdminAuth::canManageProperties() && $record !== null)
            ->form([
                Select::make('manager_id')
                    ->label('Manager')
                    ->required()
                    ->options(fn ($record) => self::eligibleManagerContactOptions($record))
                    ->helperText('Only people who already hold a manager role on this property can be added. Grant the role on the Managers tab first.'),
            ])
            ->action(function (array $data, $record): void {
                $manager = PropertyManager::where('property_id', $record->id)
                    ->where('id', $data['manager_id'])
                    ->whereNull('revoked_at')
                    ->whereIn('role', ['co_owner', 'manager', 'operator'])
                    ->first();

                if (! $manager) {
                    Notification::make()
                        ->title('That manager is no longer available.')
                        ->danger()
                        ->send();
                    return;
                }

                if ($manager->is_field_contact) {
                    Notification::make()
                        ->title('This manager is already a contact.')
                        ->warning()
                        ->send();
                    return;
                }

                $manager->is_field_contact = true;
                $manager->save();

                Notification::make()
                    ->title('Manager added as a contact.')
                    ->success()
                    ->send();
            });
    }

    private static function renderCheckInsHtml($record): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to view check-in history.</p>'
            );
        }

        try {
            $rows = app(\App\Services\Lease\CheckInService::class)->getHistoryForProperty($record->id);
        } catch (\Throwable) {
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        return new HtmlString(
            view('filament.admin.properties.check-in-log', ['rows' => $rows])->render()
        );
    }

    private static function renderManagersHtml($record): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to manage access.</p>'
            );
        }

        try {
            $managers = PropertyManager::where('property_id', $record->id)
                ->whereNull('revoked_at')
                ->orderBy('granted_at')
                ->get();
        } catch (\Throwable) {
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        if ($managers->isEmpty()) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">'
                . 'No active managers assigned. Use <strong>Grant Manager Access</strong> in the section header to add one.'
                . '</p>'
            );
        }

        // Bulk-load all referenced users in one query (avoids per-row cache/serialization issues)
        $userIds = $managers->pluck('user_id')
            ->merge($managers->pluck('granted_by_user_id'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $users = \App\Models\Identity\User::on('identity')
            ->with('profile')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $cols = '2.5fr 1fr 1.5fr 1.5fr 0.8fr';
        $hs   = 'font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
              . 'color:#6b7280;padding:0.5rem 0.75rem;border-bottom:2px solid #e5e7eb;';
        $cs   = 'font-size:0.875rem;color:#374151;padding:0.625rem 0.75rem;'
              . 'border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';

        $html  = "<div style=\"display:grid;grid-template-columns:{$cols};\">";
        $html .= "<div style=\"{$hs}\">Name</div>"
               . "<div style=\"{$hs}\">Role</div>"
               . "<div style=\"{$hs}\">Granted</div>"
               . "<div style=\"{$hs}\">Granted By</div>"
               . "<div style=\"{$hs}\">Action</div>";

        foreach ($managers as $m) {
            $user      = $users->get($m->user_id);
            $grantedBy = $users->get($m->granted_by_user_id);

            $name          = htmlspecialchars($user?->profile?->full_name ?: ($user?->email ?? '—'));
            $email         = htmlspecialchars($user?->email ?? '');
            $grantedByName = htmlspecialchars(
                $grantedBy?->profile?->full_name ?: ($grantedBy?->email ?? '—')
            );

            $roleBadge = match ($m->role) {
                'owner'    => '<span style="background:#fce7f3;color:#9d174d;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Owner</span>',
                'co_owner' => '<span style="background:#d1fae5;color:#065f46;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Co-Owner</span>',
                'manager'  => '<span style="background:#dbeafe;color:#1e40af;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Manager</span>',
                'operator' => '<span style="background:#fef3c7;color:#92400e;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">Operator</span>',
                default    => htmlspecialchars($m->role),
            };

            $granted = $m->granted_at?->format('M j, Y') ?? '—';
            $mid     = $m->id;

            $html .= "<div style=\"{$cs}\"><div>"
                   . "<div style=\"font-weight:500;\">{$name}</div>"
                   . "<div style=\"font-size:0.75rem;color:#9ca3af;\">{$email}</div>"
                   . "</div></div>";
            $html .= "<div style=\"{$cs}\">{$roleBadge}</div>";
            $html .= "<div style=\"{$cs}\">{$granted}</div>";
            $html .= "<div style=\"{$cs}\">{$grantedByName}</div>";
            $revokeIcon = svg('heroicon-m-user-minus', '', ['style' => 'width:0.9rem;height:0.9rem;flex-shrink:0;'])->toHtml();
            $html .= "<div style=\"{$cs}\">"
                   . "<button type=\"button\""
                   . " wire:click=\"revokePropertyManager('{$mid}')\""
                   . " wire:confirm=\"Revoke this manager&apos;s access?\""
                   . " style=\"display:inline-flex;align-items:center;gap:0.25rem;font-size:0.875rem;"
                   . "color:#dc2626;font-weight:500;cursor:pointer;"
                   . "background:none;border:none;padding:0;\">"
                   . "{$revokeIcon}Revoke"
                   . "</button></div>";
        }

        $html .= '</div>';
        return new HtmlString($html);
    }

    private static function renderContactPartiesHtml($record): HtmlString
    {
        if (! $record?->id) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;">Save the property first to view contacts.</p>'
            );
        }

        try {
            $directory = app(PropertyService::class)->getContactDirectory($record->id, includeManagerIds: true);
        } catch (\Throwable) {
            return new HtmlString('<p style="color:#6b7280;font-size:0.875rem;">Unavailable.</p>');
        }

        $rows = [];

        if ($directory['landowner']) {
            $rows[] = ['Landowner', $directory['landowner']];
        }
        foreach ($directory['managers'] as $m) {
            $rows[] = [$m['role_label'], $m];
        }

        if (empty($rows)) {
            return new HtmlString(
                '<p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">'
                . 'No landowner or managers resolved for this property yet.'
                . '</p>'
            );
        }

        $cols = '1.2fr 2fr 1.5fr 2fr 0.8fr';
        $hs   = 'font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
              . 'color:#6b7280;padding:0.5rem 0.75rem;border-bottom:2px solid #e5e7eb;';
        $cs   = 'font-size:0.875rem;color:#374151;padding:0.625rem 0.75rem;'
              . 'border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';

        $removeIcon = svg('heroicon-m-trash', '', ['style' => 'width:0.9rem;height:0.9rem;flex-shrink:0;'])->toHtml();

        $html  = "<div style=\"display:grid;grid-template-columns:{$cols};\">";
        $html .= "<div style=\"{$hs}\">Role</div>"
               . "<div style=\"{$hs}\">Name</div>"
               . "<div style=\"{$hs}\">Phone</div>"
               . "<div style=\"{$hs}\">Email</div>"
               . "<div style=\"{$hs}\">Action</div>";

        foreach ($rows as [$role, $c]) {
            $html .= "<div style=\"{$cs}font-weight:500;\">" . htmlspecialchars($role) . '</div>';
            $html .= "<div style=\"{$cs}\">" . htmlspecialchars($c['name'] ?? '—') . '</div>';
            $html .= "<div style=\"{$cs}\">" . htmlspecialchars($c['phone'] ? PhoneNumber::format($c['phone']) : '—') . '</div>';
            $html .= "<div style=\"{$cs}\">" . htmlspecialchars($c['email'] ?: '—') . '</div>';

            // Only opted-in managers (which carry a manager_id) can be removed as a contact.
            if (! empty($c['manager_id'])) {
                $mid   = htmlspecialchars($c['manager_id']);
                $html .= "<div style=\"{$cs}\">"
                       . "<button type=\"button\""
                       . " wire:click=\"removeManagerContact('{$mid}')\""
                       . " wire:confirm=\"Remove this manager from the contact list?\""
                       . " style=\"display:inline-flex;align-items:center;gap:0.25rem;font-size:0.875rem;"
                       . "color:#dc2626;font-weight:500;cursor:pointer;background:none;border:none;padding:0;\">"
                       . "{$removeIcon}Delete"
                       . "</button></div>";
            } else {
                $html .= "<div style=\"{$cs}color:#d1d5db;\">—</div>";
            }
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * Per-item actions on the listings repeater for day-hunt listings: view the
     * availability calendar and manage owner blackout ranges. Both only appear on
     * a saved day-hunt listing (a calendar needs a persisted listing id). Booked
     * dates are lease-driven and never edited here.
     *
     * @return array<Action>
     */
    private static function dayHuntListingActions(): array
    {
        $isSavedDayHunt = function (array $arguments, Repeater $component): bool {
            $item = $component->getRawItemState($arguments['item']);

            return ($item['listing_type'] ?? null) === 'day_hunt' && ! empty($item['id']);
        };

        return [
            Action::make('listingAvailability')
                ->label('Availability')
                ->icon('heroicon-o-calendar-days')
                ->button()
                ->color('gray')
                ->visible($isSavedDayHunt)
                ->modalHeading('Day-Hunt Availability Calendar')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function (array $arguments, Repeater $component) {
                    $item = $component->getRawItemState($arguments['item']);

                    return view('filament.admin.day-hunt-availability', [
                        'calendar' => app(PropertyService::class)->getAvailabilityCalendar($item['id']),
                    ]);
                }),
            Action::make('listingBlackouts')
                ->label('Blackouts')
                ->icon('heroicon-o-no-symbol')
                ->button()
                ->color('gray')
                ->visible($isSavedDayHunt)
                ->modalHeading('Manage Blackout Dates')
                ->modalDescription('Block dates that cannot be booked. Booked dates come from leases and are managed automatically — they cannot be edited here.')
                ->fillForm(function (array $arguments, Repeater $component): array {
                    $item = $component->getRawItemState($arguments['item']);

                    return [
                        'blocks' => array_map(
                            fn (array $b): array => [
                                'date_start' => $b['date_start'],
                                'date_end'   => $b['date_end'],
                                'reason'     => $b['reason'],
                            ],
                            app(PropertyService::class)->getBlackoutRanges($item['id']),
                        ),
                    ];
                })
                ->schema([
                    Repeater::make('blocks')
                        ->label('Blackout ranges')
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add blackout')
                        ->deleteAction(fn (Action $action) => $action
                            ->label('Delete')
                            ->icon('heroicon-o-trash')
                            ->button()
                        )
                        ->schema([
                            DatePicker::make('date_start')
                                ->label('From')
                                ->required(),
                            DatePicker::make('date_end')
                                ->label('To')
                                ->required()
                                ->afterOrEqual('date_start'),
                            Select::make('reason')
                                ->options([
                                    'blocked'     => 'Blocked',
                                    'maintenance' => 'Maintenance',
                                ])
                                ->default('blocked')
                                ->required(),
                        ]),
                ])
                ->action(function (array $arguments, array $data, Repeater $component): void {
                    $item = $component->getRawItemState($arguments['item']);

                    try {
                        app(PropertyService::class)->replaceBlackouts(
                            $item['id'],
                            $data['blocks'] ?? [],
                            auth()->id(),
                        );
                        Notification::make()->title('Blackout dates updated')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not save')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    private static function contactsRepeater(): Repeater
    {
        return Repeater::make('contacts')
            ->relationship()
            ->reorderable()
            ->orderColumn('sort_order')
            ->itemLabel(fn (array $state): string => match (true) {
                ($state['contact_type'] ?? null) === 'other' => $state['label'] ?: 'Other Contact',
                isset($state['contact_type'])                => \App\Models\Property\PropertyContact::TYPES[$state['contact_type']] ?? 'Contact',
                default                                      => 'New Contact',
            })
            ->addAction(fn (\Filament\Actions\Action $action) => $action
                ->label('Add Contact')
                ->icon('heroicon-o-plus-circle')
            )
            ->addActionAlignment(Alignment::Start)
            ->columns(2)
            ->schema([
                Select::make('contact_type')
                    ->label('Contact Type')
                    ->required()
                    ->live()
                    ->options(\App\Models\Property\PropertyContact::TYPES)
                    ->default('law_enforcement'),
                TextInput::make('label')
                    ->label('Custom Label')
                    ->maxLength(100)
                    ->placeholder('Neighbor, Nearest Hospital, …')
                    ->helperText('Shown as the contact heading.')
                    ->visible(fn (Get $get) => $get('contact_type') === 'other'),
                TextInput::make('name')
                    ->label('Contact Name')
                    ->maxLength(150)
                    ->placeholder('Sgt. John Smith'),
                TextInput::make('organization')
                    ->label('Agency / Organization')
                    ->maxLength(150)
                    ->placeholder('County Sheriff\'s Office'),
                TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    // Accept the formatted display value (+1 (123) 456-7890); the
                    // default tel regex rejects a paren group after the country code.
                    ->telRegex('/^[\d\s()+\-.\/]*$/')
                    ->placeholder('+1 (123) 456-7890')
                    ->formatStateUsing(fn (?string $state) => PhoneNumber::format($state))
                    // Store clean digits; everything formats for display via PhoneNumber.
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (preg_replace('/\D+/', '', $state) ?: null) : null)
                    ->maxLength(30),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->placeholder('Non-emergency line, hours, where to meet, etc.'),
            ]);
    }

    private static function amenitiesTabSchema(): array
    {
        $sections = PropertyAmenity::distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($cat) =>
                Section::make(PropertyAmenity::categoryLabel($cat))
                    ->schema([self::amenityCategoryCheckboxList($cat)])
            )
            ->all();

        return [Grid::make(2)->schema($sections)];
    }

    private static function amenityCategoryCheckboxList(string $category): CheckboxList
    {
        return CheckboxList::make("amenities_{$category}")
            ->hiddenLabel()
            ->options(fn () => PropertyAmenity::where('category', $category)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray())
            ->columns(3)
            ->gridDirection('row')
            ->columnSpanFull()
            ->afterStateHydrated(function (CheckboxList $component) use ($category) {
                $record = $component->getRecord();
                if (! $record) {
                    $component->state([]);
                    return;
                }
                $component->state(
                    $record->amenities()
                        ->where('property_amenities.category', $category)
                        ->pluck('property_amenities.id')
                        ->toArray()
                );
            });
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('General Info')
                            ->schema([
                                Section::make()
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('title')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        TextInput::make('slug')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true)
                                            ->columnSpanFull(),
                                        Textarea::make('description')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                        Select::make('status')
                                            ->required()
                                            ->options([
                                                'draft'     => 'Draft',
                                                'active'    => 'Active',
                                                'suspended' => 'Suspended',
                                                'archived'  => 'Archived',
                                            ])
                                            ->default('draft'),
                                        Select::make('state_code')
                                            ->label('State')
                                            ->options(\App\Support\UsStates::names())
                                            ->searchable()
                                            ->required(),
                                        TextInput::make('county')
                                            ->required()
                                            ->maxLength(100),
                                        Grid::make(2)
                                            ->columnSpanFull()
                                            ->schema([
                                                TextInput::make('center_lat')
                                                    ->label('Latitude')
                                                    ->numeric()
                                                    ->minValue(-90)
                                                    ->maxValue(90)
                                                    ->placeholder('30.267153')
                                                    ->helperText('WGS84 decimal degrees — map pin only.'),
                                                TextInput::make('center_lng')
                                                    ->label('Longitude')
                                                    ->numeric()
                                                    ->minValue(-180)
                                                    ->maxValue(180)
                                                    ->placeholder('-97.743057')
                                                    ->helperText('Negative values are West.'),
                                            ]),
                                        Grid::make(2)
                                            ->columnSpanFull()
                                            ->schema([
                                                TextInput::make('total_acres')
                                                    ->label('Total Acres')
                                                    ->required()
                                                    ->numeric()
                                                    ->minValue(1),
                                                TextInput::make('huntable_acres')
                                                    ->label('Huntable Acres')
                                                    ->numeric()
                                                    ->minValue(0),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Game Type')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('species')
                                            ->relationship()
                                            ->columns(2)
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Game Type')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->schema([
                                                Select::make('species_code')
                                                    ->label('Species')
                                                    ->required()
                                                    ->options(self::$speciesOptions),
                                                Toggle::make('is_primary')
                                                    ->label('Primary Species')
                                                    ->helperText('Main huntable species for this property.')
                                                    ->inline(false),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Property Rules')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('rules')
                                            ->relationship()
                                            ->reorderable('sort_order')
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Rule')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->schema([
                                                Textarea::make('rule_text')
                                                    ->label('Rule')
                                                    ->required()
                                                    ->rows(2)
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                                TextInput::make('sort_order')
                                                    ->label('Order')
                                                    ->numeric()
                                                    ->default(0),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Amenities')
                            ->schema(static::amenitiesTabSchema()),

                        Tab::make('Photos')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Photo Gallery')
                                    ->description('Photos shown on the public listing. The primary photo is the cover image; use the arrows to set display order.')
                                    ->headerActions([self::uploadPhotosAction()])
                                    ->schema([
                                        Placeholder::make('property_photos_display')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component) {
                                                return static::renderPhotosHtml($component->getRecord());
                                            }),
                                    ]),
                            ]),

                        Tab::make('Map')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Boundary Map')
                                    ->description('The boundary map is shown on the public listing (without markers). Add markers for amenities, game locations, stands, and other points of interest — markers are admin/member only.')
                                    ->headerActions([self::uploadMapImagesAction()])
                                    ->schema([
                                        Placeholder::make('property_map_editor')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component, $livewire) {
                                                return static::renderMapEditorHtml(
                                                    $component->getRecord(),
                                                    $livewire->selectedMapImageId ?? null,
                                                );
                                            }),
                                    ]),
                            ]),

                        Tab::make('Listings')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        Repeater::make('listings')
                                            ->relationship()
                                            ->itemLabel(fn(array $state): string => isset($state['id'])
                                                ? 'ID · ' . strtoupper(substr($state['id'], 0, 8))
                                                : 'New Listing'
                                            )
                                            ->extraItemActions(self::dayHuntListingActions())
                                            ->deleteAction(fn(Action $action) => $action
                                                ->label('Delete')
                                                ->icon('heroicon-o-trash')
                                                ->button()
                                            )
                                            ->addAction(fn(\Filament\Actions\Action $action) => $action
                                                ->label('Add Listing')
                                                ->icon('heroicon-o-plus-circle')
                                            )
                                            ->addActionAlignment(Alignment::Start)
                                            ->columns(2)
                                            ->schema([
                                                Select::make('listing_type')
                                                    ->label('Type')
                                                    ->required()
                                                    ->live()
                                                    ->options([
                                                        'annual_lease'   => 'Annual Lease',
                                                        'seasonal_lease' => 'Seasonal Lease',
                                                        'day_hunt'       => 'Day Hunt',
                                                        'auction'        => 'Auction',
                                                    ]),
                                                Select::make('status')
                                                    ->required()
                                                    ->options([
                                                        'draft'    => 'Draft',
                                                        'active'   => 'Active',
                                                        'sold_out' => 'Sold Out',
                                                        'expired'  => 'Expired',
                                                        'archived' => 'Archived',
                                                    ])
                                                    ->default('draft'),
                                                Select::make('visibility')
                                                    ->required()
                                                    ->options([
                                                        'public'       => 'Public',
                                                        'members_only' => 'Members Only',
                                                        'invite_only'  => 'Invite Only',
                                                    ])
                                                    ->default('public'),
                                                Toggle::make('auto_renew')
                                                    ->label('Auto Renew')
                                                    ->default(false)
                                                    ->inline(false),
                                                DatePicker::make('season_start')
                                                    ->label('Season Start'),
                                                DatePicker::make('season_end')
                                                    ->label('Season End')
                                                    ->afterOrEqual('season_start'),
                                                TextInput::make('max_hunters')
                                                    ->label('Max Hunters')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),
                                                TextInput::make('min_hunters')
                                                    ->label('Min Hunters')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->placeholder('No minimum'),
                                                TextInput::make('price_per_hunter')
                                                    ->label(fn (Get $get): string => $get('listing_type') === 'day_hunt' ? 'Price Per Hunter / Day' : 'Price Per Hunter')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('price_per_hunter_weekly')
                                                    ->label('Price Per Hunter / Week')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0)
                                                    ->helperText('Day-hunt only — discounted rate applied to each full 7-day block. Leave blank for no weekly discount.')
                                                    ->visible(fn (Get $get): bool => $get('listing_type') === 'day_hunt'),
                                                TextInput::make('price_total')
                                                    ->label('Total Price')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('deposit_amount')
                                                    ->label('Deposit ($)')
                                                    ->numeric()
                                                    ->prefix('$')
                                                    ->minValue(0),
                                                TextInput::make('deposit_percent')
                                                    ->label('Deposit (%)')
                                                    ->numeric()
                                                    ->suffix('%')
                                                    ->minValue(0)
                                                    ->maxValue(100),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Check In/Out')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Field Check-In Log')
                                    ->description('A running record of every hunter check-in and check-out on this property, across all leases. Newest first.')
                                    ->schema([
                                        Placeholder::make('property_checkins_display')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component) {
                                                return static::renderCheckInsHtml($component->getRecord());
                                            }),
                                    ]),
                            ]),

                        Tab::make('Managers')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Active Managers')
                                    ->description('Users who can manage this property on behalf of the owner.')
                                    ->headerActions([self::grantManagerAction()])
                                    ->schema([
                                        Placeholder::make('property_managers_display')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component) {
                                                return static::renderManagersHtml($component->getRecord());
                                            }),
                                    ]),
                            ]),

                        Tab::make('Contacts')
                            ->visible(fn ($record) => $record !== null)
                            ->schema([
                                Section::make('Landowner & Managers')
                                    ->description('Landowner is pulled automatically from the owner account. Managers are opt-in — use Add Manager Contact to expose a manager to hunters as a field contact.')
                                    ->headerActions([self::addManagerContactAction()])
                                    ->schema([
                                        Placeholder::make('property_contact_parties_display')
                                            ->hiddenLabel()
                                            ->content(function (Placeholder $component) {
                                                return static::renderContactPartiesHtml($component->getRecord());
                                            }),
                                    ]),
                                Section::make('Emergency & Local Contacts')
                                    ->description('Local law enforcement, game warden, emergency, and any other contacts (e.g. a neighbor) a hunter may need in the field. These are shown to active lessees on the lease page and the mobile app.')
                                    ->schema([
                                        self::contactsRepeater(),
                                    ]),
                            ]),

                    ]),
            ]);
    }
}
