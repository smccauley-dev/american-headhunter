<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'roles';

    protected $fillable = ['name', 'display_name', 'description', 'is_system'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system'  => 'boolean',
        ]);
    }

    // No updated_at on roles — override base casts
    protected function baseCasts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id')
                    ->withPivot('granted_at', 'granted_by_user_id');
    }
}
