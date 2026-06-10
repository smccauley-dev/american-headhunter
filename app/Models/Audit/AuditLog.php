<?php

namespace App\Models\Audit;

use App\Models\BaseModel;

class AuditLog extends BaseModel
{
    protected $connection = 'audit';
    protected $table      = 'audit_log';
    public    $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'old_values'     => 'array',
            'new_values'     => 'array',
            'occurred_at'    => 'datetime',
        ];
    }

    // Append-only — never update or delete
    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }
        throw new \RuntimeException('AuditLog records are immutable.');
    }

    public function delete(): ?bool
    {
        throw new \RuntimeException('AuditLog records cannot be deleted.');
    }
}
