<?php

namespace App\Filament\Admin\Pages;

use App\Models\Audit\AuditLog;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogViewer extends Page implements HasTable
{
    use InteractsWithTable;
    use HasIconPageHeading;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->headingWithIcon('Audit Log', 'heroicon-o-clipboard-document-list');
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $title       = 'Audit Log';
    protected static ?int    $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Users & Access';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSecurity();
    }

    public function getView(): string
    {
        return 'filament.admin.pages.audit-log-viewer';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->orderBy('occurred_at', 'desc'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i:s')
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'create'     => 'success',
                        'update'     => 'warning',
                        'delete'     => 'danger',
                        'login'      => 'info',
                        'login_fail' => 'danger',
                        default      => 'gray',
                    }),
                TextColumn::make('action_summary')
                    ->label('Summary')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('table_name')
                    ->label('Table')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('source_database')
                    ->label('Database')
                    ->fontFamily('mono'),
                TextColumn::make('user_id')
                    ->label('User ID')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->options([
                        'create'     => 'Create',
                        'update'     => 'Update',
                        'delete'     => 'Delete',
                        'login'      => 'Login',
                        'login_fail' => 'Failed Login',
                    ]),
                SelectFilter::make('source_database')
                    ->options([
                        'identity' => 'Identity (DB 1)',
                        'property' => 'Property (DB 2)',
                        'lease'    => 'Lease (DB 3)',
                        'billing'  => 'Billing (DB 4)',
                        'platform' => 'Platform (DB 12)',
                    ]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(null);
    }
}
