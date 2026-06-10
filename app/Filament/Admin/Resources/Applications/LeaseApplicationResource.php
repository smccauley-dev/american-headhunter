<?php

namespace App\Filament\Admin\Resources\Applications;

use App\Filament\Admin\Resources\Applications\Pages\ListLeaseApplications;
use App\Filament\Admin\Resources\Applications\Pages\ViewLeaseApplication;
use App\Models\Lease\LeaseApplication;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaseApplicationResource extends Resource
{
    protected static ?string $model = LeaseApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Application';

    protected static ?string $pluralModelLabel = 'Applications';

    protected static ?string $navigationLabel = 'Applications';

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageLeases();
    }

    public static function canCreate(): bool
    {
        return false; // Applications come through the customer portal only
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Application')
                    ->formatStateUsing(fn (string $state): string => 'AH-' . strtoupper(substr($state, 0, 8)))
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('listing_id')
                    ->label('Listing')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8)))
                    ->fontFamily('mono')
                    ->copyable(),

                TextColumn::make('application_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'individual'  => 'info',
                        'club'        => 'warning',
                        'club_member' => 'gray',
                        default       => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'individual'  => 'Individual',
                        'club'        => 'Club',
                        'club_member' => 'Club Member',
                        default       => $state,
                    }),

                TextColumn::make('desired_hunters')
                    ->label('Hunters')
                    ->numeric()
                    ->alignCenter(),

                TextColumn::make('proposed_start')
                    ->label('Start')
                    ->date('M j, Y')
                    ->sortable(),

                TextColumn::make('proposed_end')
                    ->label('End')
                    ->date('M j, Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'   => 'warning',
                        'approved'  => 'success',
                        'rejected'  => 'danger',
                        'withdrawn' => 'gray',
                        'countered' => 'info',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Pending',
                        'approved'  => 'Approved',
                        'rejected'  => 'Rejected',
                        'withdrawn' => 'Withdrawn',
                        'countered' => 'Countered',
                        default     => $state,
                    }),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'approved'  => 'Approved',
                        'rejected'  => 'Rejected',
                        'withdrawn' => 'Withdrawn',
                        'countered' => 'Countered',
                    ]),

                SelectFilter::make('application_type')
                    ->label('Type')
                    ->options([
                        'individual'  => 'Individual',
                        'club'        => 'Club',
                        'club_member' => 'Club Member',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaseApplications::route('/'),
            'view'  => ViewLeaseApplication::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
