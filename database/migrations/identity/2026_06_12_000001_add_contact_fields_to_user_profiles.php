<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddContactFieldsToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // All columns hold base64-encoded pgp_sym_encrypt output (Key: identity).
        // Encryption/decryption is handled transparently by the HasEncryptedFields
        // trait on the UserProfile model — never read these raw. state_code and
        // zip_code remain plaintext (indexed, used for filtering).
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS address_line1                  TEXT NULL,
                ADD COLUMN IF NOT EXISTS address_line2                  TEXT NULL,
                ADD COLUMN IF NOT EXISTS city                           TEXT NULL,
                ADD COLUMN IF NOT EXISTS emergency_contact_name         TEXT NULL,
                ADD COLUMN IF NOT EXISTS emergency_contact_relationship TEXT NULL,
                ADD COLUMN IF NOT EXISTS emergency_contact_phone        TEXT NULL,
                ADD COLUMN IF NOT EXISTS emergency_contact_email        TEXT NULL;

            COMMENT ON COLUMN user_profiles.address_line1 IS
                'encrypted (pgp_sym, identity key) — mailing street address line 1';
            COMMENT ON COLUMN user_profiles.address_line2 IS
                'encrypted (pgp_sym, identity key) — mailing address line 2 (apt/unit)';
            COMMENT ON COLUMN user_profiles.city IS
                'encrypted (pgp_sym, identity key) — mailing city';
            COMMENT ON COLUMN user_profiles.emergency_contact_name IS
                'encrypted (pgp_sym, identity key) — emergency contact full name';
            COMMENT ON COLUMN user_profiles.emergency_contact_relationship IS
                'encrypted (pgp_sym, identity key) — emergency contact relationship';
            COMMENT ON COLUMN user_profiles.emergency_contact_phone IS
                'encrypted (pgp_sym, identity key) — emergency contact phone';
            COMMENT ON COLUMN user_profiles.emergency_contact_email IS
                'encrypted (pgp_sym, identity key) — emergency contact email';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS address_line1,
                DROP COLUMN IF EXISTS address_line2,
                DROP COLUMN IF EXISTS city,
                DROP COLUMN IF EXISTS emergency_contact_name,
                DROP COLUMN IF EXISTS emergency_contact_relationship,
                DROP COLUMN IF EXISTS emergency_contact_phone,
                DROP COLUMN IF EXISTS emergency_contact_email;
        SQL);
    }
}
