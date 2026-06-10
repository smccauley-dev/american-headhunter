<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasCreatePageScaffold;
use App\Filament\Admin\Resources\Users\AdminUserResource;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Services\Audit\AuditService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends CreateRecord
{
    use HasCreatePageScaffold;
    protected static string $resource = AdminUserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firstName = $data['first_name'] ?? '';
        $lastName  = $data['last_name']  ?? '';
        $password  = $data['password']   ?? '';

        $user = User::create([
            'email'              => $data['email'],
            'password_hash'      => Hash::make($password),
            'status'             => $data['status'] ?? 'active',
            'account_type'       => 'staff',
            'email_verified_at'  => now(),
            'trust_score'        => 100,
        ]);

        UserProfile::create([
            'user_id'    => $user->id,
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);

        app(AuditService::class)->log(
            eventType:      'create',
            sourceDatabase: 'identity',
            tableName:      'users',
            recordId:       $user->id,
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  "Admin user created: {$data['email']}",
            changedFields:  ['email', 'status', 'account_type'],
        );

        return $user;
    }

    protected function afterCreate(): void
    {
        $roles = $this->getRecord()
            ->roles()
            ->pluck('name')
            ->sort()
            ->values()
            ->toArray();

        if (! empty($roles)) {
            app(AuditService::class)->log(
                eventType:      'role_change',
                sourceDatabase: 'identity',
                tableName:      'user_roles',
                recordId:       $this->getRecord()->id,
                userId:         Auth::id(),
                ipAddress:      request()->ip(),
                userAgent:      request()->userAgent(),
                actionSummary:  "Admin user created with roles: {$this->getRecord()->email}",
                changedFields:  ['roles'],
                oldValues:      ['roles' => []],
                newValues:      ['roles' => $roles],
            );
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Handled in handleRecordCreation — strip virtual fields from base save
        unset($data['first_name'], $data['last_name'], $data['password']);
        return $data;
    }
}
