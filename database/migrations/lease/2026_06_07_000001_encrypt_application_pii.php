<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    // Fields being encrypted in lease_applications
    private array $appFields = [
        'message',
    ];

    // Fields being encrypted in lease_application_hunters
    private array $hunterFields = [
        'email',
        'home_phone',
        'cell_phone',
        'address_line1',
        'address_line2',
        'city',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'medical_conditions',
        'dl_number',
        'hunting_license_number',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->connection);
        $key  = config('encryption_keys.lease');

        if (! $key) {
            throw new \RuntimeException('ENCRYPTION_KEY_LEASE is not set — cannot run PII encryption migration.');
        }

        // ── Step 1: Add temporary _new columns ────────────────────────────────

        $appAlters = implode(', ', array_map(
            fn($f) => "ADD COLUMN IF NOT EXISTS {$f}_new TEXT NULL",
            $this->appFields
        ));
        $conn->statement("ALTER TABLE lease_applications {$appAlters}");

        $hunterAlters = implode(', ', array_map(
            fn($f) => "ADD COLUMN IF NOT EXISTS {$f}_new TEXT NULL",
            $this->hunterFields
        ));
        $conn->statement("ALTER TABLE lease_application_hunters {$hunterAlters}");

        // ── Step 2: Backfill encrypted values from existing plaintext rows ─────

        $apps = $conn->table('lease_applications')
            ->select(array_merge(['id'], $this->appFields))
            ->get();

        foreach ($apps as $row) {
            $updates = [];
            foreach ($this->appFields as $field) {
                if ($row->$field !== null) {
                    $enc = $conn->selectOne(
                        "SELECT encode(pgp_sym_encrypt(?, ?), 'base64') AS enc",
                        [$row->$field, $key]
                    );
                    $updates["{$field}_new"] = $enc->enc;
                }
            }
            if ($updates) {
                $conn->table('lease_applications')->where('id', $row->id)->update($updates);
            }
        }

        $hunters = $conn->table('lease_application_hunters')
            ->select(array_merge(['id'], $this->hunterFields))
            ->get();

        foreach ($hunters as $row) {
            $updates = [];
            foreach ($this->hunterFields as $field) {
                if ($row->$field !== null) {
                    $enc = $conn->selectOne(
                        "SELECT encode(pgp_sym_encrypt(?, ?), 'base64') AS enc",
                        [$row->$field, $key]
                    );
                    $updates["{$field}_new"] = $enc->enc;
                }
            }
            if ($updates) {
                $conn->table('lease_application_hunters')->where('id', $row->id)->update($updates);
            }
        }

        // ── Step 3: Swap — drop plaintext columns, rename encrypted ones ───────

        // DROP columns can be batched; RENAME COLUMN cannot — each needs its own ALTER TABLE
        $appDrops = implode(', ', array_map(fn($f) => "DROP COLUMN {$f}", $this->appFields));
        $conn->unprepared("ALTER TABLE lease_applications {$appDrops}");
        foreach ($this->appFields as $f) {
            $conn->unprepared("ALTER TABLE lease_applications RENAME COLUMN {$f}_new TO {$f}");
        }

        $hunterDrops = implode(', ', array_map(fn($f) => "DROP COLUMN {$f}", $this->hunterFields));
        $conn->unprepared("ALTER TABLE lease_application_hunters {$hunterDrops}");
        foreach ($this->hunterFields as $f) {
            $conn->unprepared("ALTER TABLE lease_application_hunters RENAME COLUMN {$f}_new TO {$f}");
        }
    }

    public function down(): void
    {
        $conn = DB::connection($this->connection);
        $key  = config('encryption_keys.lease');

        if (! $key) {
            throw new \RuntimeException('ENCRYPTION_KEY_LEASE is not set — cannot decrypt PII during rollback.');
        }

        // ── Step 1: Add temporary plaintext columns ────────────────────────────

        $appAlters = implode(', ', array_map(
            fn($f) => "ADD COLUMN IF NOT EXISTS {$f}_plain TEXT NULL",
            $this->appFields
        ));
        $conn->statement("ALTER TABLE lease_applications {$appAlters}");

        $hunterAlters = implode(', ', array_map(
            fn($f) => "ADD COLUMN IF NOT EXISTS {$f}_plain TEXT NULL",
            $this->hunterFields
        ));
        $conn->statement("ALTER TABLE lease_application_hunters {$hunterAlters}");

        // ── Step 2: Decrypt back to plaintext ─────────────────────────────────

        $apps = $conn->table('lease_applications')
            ->select(array_merge(['id'], $this->appFields))
            ->get();

        foreach ($apps as $row) {
            $updates = [];
            foreach ($this->appFields as $field) {
                if ($row->$field !== null) {
                    $dec = $conn->selectOne(
                        "SELECT pgp_sym_decrypt(decode(?, 'base64'), ?) AS dec",
                        [$row->$field, $key]
                    );
                    $updates["{$field}_plain"] = $dec?->dec;
                }
            }
            if ($updates) {
                $conn->table('lease_applications')->where('id', $row->id)->update($updates);
            }
        }

        $hunters = $conn->table('lease_application_hunters')
            ->select(array_merge(['id'], $this->hunterFields))
            ->get();

        foreach ($hunters as $row) {
            $updates = [];
            foreach ($this->hunterFields as $field) {
                if ($row->$field !== null) {
                    $dec = $conn->selectOne(
                        "SELECT pgp_sym_decrypt(decode(?, 'base64'), ?) AS dec",
                        [$row->$field, $key]
                    );
                    $updates["{$field}_plain"] = $dec?->dec;
                }
            }
            if ($updates) {
                $conn->table('lease_application_hunters')->where('id', $row->id)->update($updates);
            }
        }

        // ── Step 3: Swap back ──────────────────────────────────────────────────

        $appDrops = implode(', ', array_map(fn($f) => "DROP COLUMN {$f}", $this->appFields));
        $conn->unprepared("ALTER TABLE lease_applications {$appDrops}");
        foreach ($this->appFields as $f) {
            $conn->unprepared("ALTER TABLE lease_applications RENAME COLUMN {$f}_plain TO {$f}");
        }

        $hunterDrops = implode(', ', array_map(fn($f) => "DROP COLUMN {$f}", $this->hunterFields));
        $conn->unprepared("ALTER TABLE lease_application_hunters {$hunterDrops}");
        foreach ($this->hunterFields as $f) {
            $conn->unprepared("ALTER TABLE lease_application_hunters RENAME COLUMN {$f}_plain TO {$f}");
        }
    }
};
