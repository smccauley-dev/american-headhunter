<?php

namespace App\Models;

abstract class ReadOnlyModel extends BaseModel
{
    protected $connection = 'analytics';

    public function save(array $options = []): bool
    {
        throw new \LogicException(
            static::class . ' is read-only from the application layer. Write via ETL jobs only.'
        );
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(static::class . ' is read-only from the application layer.');
    }

    public function delete(): bool|null
    {
        throw new \LogicException(static::class . ' is read-only from the application layer.');
    }
}
