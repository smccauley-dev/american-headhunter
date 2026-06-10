<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'permissions';

    protected $fillable = ['name', 'display_name', 'description', 'category'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }
}
