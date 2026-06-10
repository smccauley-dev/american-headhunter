<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE roles (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                name         VARCHAR(50)  NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                description  TEXT         NULL,
                is_system    BOOLEAN      NOT NULL DEFAULT false,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_roles_name ON roles (name);

            CREATE TABLE permissions (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                name         VARCHAR(100) NOT NULL,
                display_name VARCHAR(150) NOT NULL,
                description  TEXT         NULL,
                category     VARCHAR(50)  NOT NULL,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_permissions_name      ON permissions (name);
            CREATE        INDEX idx_permissions_category ON permissions (category);

            CREATE TABLE role_permissions (
                role_id       UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
                permission_id UUID NOT NULL REFERENCES permissions (id) ON DELETE CASCADE,
                PRIMARY KEY (role_id, permission_id)
            );

            CREATE INDEX idx_role_permissions_permission_id ON role_permissions (permission_id);

            CREATE TABLE user_roles (
                user_id              UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role_id              UUID        NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
                granted_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                granted_by_user_id   UUID        NULL REFERENCES users (id) ON DELETE SET NULL,
                PRIMARY KEY (user_id, role_id)
            );

            CREATE INDEX idx_user_roles_role_id ON user_roles (role_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS user_roles CASCADE;
            DROP TABLE IF EXISTS role_permissions CASCADE;
            DROP TABLE IF EXISTS permissions CASCADE;
            DROP TABLE IF EXISTS roles CASCADE;
        SQL);
    }
};
