<?php

namespace App\Models;

abstract class ImmutableModel extends BaseModel
{
    protected $connection = 'audit';

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }
        throw new \LogicException(static::class . ' is immutable — records cannot be updated.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be updated.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new \LogicException(static::class . ' is immutable — records cannot be deleted.');
    }
}
