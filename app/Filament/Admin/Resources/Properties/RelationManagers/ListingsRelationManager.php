<?php

namespace App\Filament\Admin\Resources\Properties\RelationManagers;

use App\Models\Property\PropertyListing;
use App\Services\Property\PropertyService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Listing Details')
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
                    ]),

                Section::make('Season Dates')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('season_start')
                            ->label('Season Start'),
                        DatePicker::make('season_end')
                            ->label('Season End')
                            ->afterOrEqual('season_start'),
                    ]),

                Section::make('Hunter Limits')
                    ->columns(2)
                    ->schema([
                        TextInput::make('min_hunters')
                            ->label('Minimum Hunters')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('No minimum'),
                        TextInput::make('max_hunters')
                            ->label('Maximum Hunters')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ]),

                Section::make('Pricing')
                    ->columns(2)
                    ->description('Set either Price Per Hunter or Total Price — not both.')
                    ->schema([
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
                            ->label('Deposit Amount')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->helperText('Fixed dollar deposit — leave blank to use percent.'),
                        TextInput::make('deposit_percent')
                            ->label('Deposit Percent')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('Percent of total — leave blank to use fixed amount.'),
                    ]),

                Section::make('Amenities')
                    ->schema([
                        Select::make('amenities')
                            ->relationship('amenities', 'name')
                            ->multiple()
                            ->preload()
                            ->label('Included Amenities')
                            ->helperText('Select all amenities available to hunters on this listing.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Listing ID')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8)))
                    ->copyable()
                    ->copyMessage('Listing ID copied'),
                TextColumn::make('listing_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'annual_lease'   => 'Annual Lease',
                        'seasonal_lease' => 'Seasonal Lease',
                        'day_hunt'       => 'Day Hunt',
                        'auction'        => 'Auction',
                        default          => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'annual_lease'   => 'success',
                        'seasonal_lease' => 'info',
                        'day_hunt'       => 'warning',
                        'auction'        => 'danger',
                        default          => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'draft'    => 'gray',
                        'sold_out' => 'warning',
                        'expired'  => 'danger',
                        'archived' => 'warning',
                        default    => 'gray',
                    }),
                TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'public'       => 'success',
                        'members_only' => 'info',
                        'invite_only'  => 'warning',
                        default        => 'gray',
                    }),
                TextColumn::make('price_per_hunter')
                    ->label('Per Hunter')
                    ->money('USD')
                    ->placeholder('—'),
                TextColumn::make('price_total')
                    ->label('Total')
                    ->money('USD')
                    ->placeholder('—'),
                TextColumn::make('season_start')
                    ->label('Starts')
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('season_end')
                    ->label('Ends')
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('max_hunters')
                    ->label('Max'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Listing'),
            ])
            ->actions([
                Action::make('availability')
                    ->label('Availability')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (PropertyListing $record): bool => $record->listing_type === 'day_hunt')
                    ->modalHeading('Day-Hunt Availability Calendar')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (PropertyListing $record) => view('filament.admin.day-hunt-availability', [
                        'calendar' => app(PropertyService::class)->getAvailabilityCalendar($record->id),
                    ])),
                Action::make('blackouts')
                    ->label('Blackouts')
                    ->icon('heroicon-o-no-symbol')
                    ->visible(fn (PropertyListing $record): bool => $record->listing_type === 'day_hunt')
                    ->modalHeading('Manage Blackout Dates')
                    ->modalDescription('Block dates that cannot be booked. Booked dates come from leases and are managed automatically — they cannot be edited here.')
                    ->fillForm(fn (PropertyListing $record): array => [
                        'blocks' => array_map(
                            fn (array $b): array => [
                                'date_start' => $b['date_start'],
                                'date_end'   => $b['date_end'],
                                'reason'     => $b['reason'],
                            ],
                            app(PropertyService::class)->getBlackoutRanges($record->id),
                        ),
                    ])
                    ->schema([
                        Repeater::make('blocks')
                            ->label('Blackout ranges')
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add blackout')
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
                    ->action(function (array $data, PropertyListing $record): void {
                        try {
                            app(PropertyService::class)->replaceBlackouts(
                                $record->id,
                                $data['blocks'] ?? [],
                                auth()->id(),
                            );
                            Notification::make()->title('Blackout dates updated')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not save')->body($e->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
