<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\Users\AdminUserResource;
use App\Services\Audit\AuditService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class EditAdminUser extends EditRecord
{
    use HasEditPageScaffold;
    protected static string $resource = AdminUserResource::class;

    private array $rolesBefore = [];

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->getRecord()
            ->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->toArray();
    }

    protected function afterSave(): void
    {
        $rolesAfter = $this->getRecord()
            ->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->toArray();

        if ($this->rolesBefore !== $rolesAfter) {
            app(AuditService::class)->log(
                eventType:      'role_change',
                sourceDatabase: 'identity',
                tableName:      'user_roles',
                recordId:       $this->getRecord()->id,
                userId:         Auth::id(),
                ipAddress:      request()->ip(),
                userAgent:      request()->userAgent(),
                actionSummary:  "Admin roles changed: {$this->getRecord()->email}",
                changedFields:  ['roles'],
                oldValues:      ['roles' => $this->rolesBefore],
                newValues:      ['roles' => $rolesAfter],
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => \App\Support\AdminAuth::isSuperAdmin()
                    && $this->getRecord()->id !== Auth::id()
                ),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $profile = $this->getRecord()->profile;
        $data['first_name'] = $profile?->first_name ?? '';
        $data['last_name']  = $profile?->last_name  ?? '';
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firstName = $data['first_name'] ?? null;
        $lastName  = $data['last_name']  ?? null;
        $password  = $data['password']   ?? null;

        $updateData = ['email' => $data['email'], 'status' => $data['status']];
        if ($password) {
            $updateData['password_hash'] = Hash::make($password);
        }

        $record->update($updateData);

        if ($firstName !== null || $lastName !== null) {
            $record->profile()->updateOrCreate(
                ['user_id' => $record->id],
                array_filter([
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ], fn ($v) => $v !== null)
            );
        }

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'identity',
            tableName:      'users',
            recordId:       $record->id,
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  "Admin user updated: {$record->email}",
            changedFields:  array_keys($updateData),
        );

        return $record;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['first_name'], $data['last_name'], $data['password']);
        return $data;
    }
}
