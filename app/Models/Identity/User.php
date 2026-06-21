<?php

namespace App\Models\Identity;

use App\Models\BaseModelWithSoftDeletes;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends BaseModelWithSoftDeletes implements AuthenticatableContract, FilamentUser, HasName
{
    use Authenticatable, HasApiTokens, Notifiable;

    protected $connection = 'identity';
    protected $table      = 'users';

    protected $fillable = [
        'email',
        'phone',
        'password_hash',
        'status',
        'account_type',
        'trust_score',
        'is_veteran',
        'is_first_responder',
        'is_profile_public',
        'username',
        'discord_user_id',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'phone_verified_at',
        'intended_plan_key',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'email_verified_at'    => 'datetime',
            'phone_verified_at'    => 'datetime',
            'locked_until'         => 'datetime',
            'last_login_at'        => 'datetime',
            'is_veteran'           => 'boolean',
            'is_first_responder'   => 'boolean',
            'is_profile_public'    => 'boolean',
            'username'             => 'string',
            'trust_score'          => 'integer',
            'failed_login_attempts' => 'integer',
        ]);
    }

    // ── Relationships within DB 1 (same connection) ──────────────────────────

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(HunterCredentials::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
                    ->withPivot('granted_at', 'granted_by_user_id');
    }

    public function mfaConfigurations(): HasMany
    {
        return $this->hasMany(MfaConfiguration::class, 'user_id');
    }

    public function oauthConnections(): HasMany
    {
        return $this->hasMany(OauthConnection::class, 'user_id');
    }

    public function loginHistory(): HasMany
    {
        return $this->hasMany(LoginHistory::class, 'user_id');
    }

    public function trustScoreEvents(): HasMany
    {
        return $this->hasMany(TrustScoreEvent::class, 'user_id');
    }

    public function consentLog(): HasMany
    {
        return $this->hasMany(ConsentLog::class, 'user_id');
    }

    // ── Cross-DB helpers ─────────────────────────────────────────────────────

    public function getAvatarUrl(): ?string
    {
        $avatarId = $this->profile?->avatar_document_id;
        if (! $avatarId) {
            return null;
        }
        return app(\App\Services\Documents\DocumentService::class)->getUrl($avatarId);
    }

    // ── Auth contract — maps password_hash column to Laravel auth ────────────

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    // ── Filament contracts ────────────────────────────────────────────────────

    public function getFilamentName(): string
    {
        $profile = $this->profile;

        if ($profile) {
            return trim("{$profile->first_name} {$profile->last_name}") ?: $this->email;
        }

        return $this->email;
    }

    // ── Filament access control ───────────────────────────────────────────────

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(
            'super_admin', 'global_admin', 'property_admin',
            'security_admin', 'article_admin', 'staff'
        );
    }

    // ── Convenience ──────────────────────────────────────────────────────────

    public function hasRole(string $roleName): bool
    {
        return $this->roles->contains('name', $roleName);
    }

    public function hasAnyRole(string ...$roleNames): bool
    {
        return $this->roles->whereIn('name', $roleNames)->isNotEmpty();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
