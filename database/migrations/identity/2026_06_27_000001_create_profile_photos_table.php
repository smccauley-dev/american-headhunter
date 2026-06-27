<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // Metadata for a hunter's profile-gallery photos. The image bytes + base
        // file record live in DB 11 (documents) as document_type='profile_photo';
        // this table holds the user-facing extras (caption, tags, location, order)
        // keyed by document_id. No RLS — ownership is enforced in the service/
        // controller layer, exactly like user_profiles and documents themselves.
        //
        // GPS is stored as plain numeric lat/long (display metadata), NOT PostGIS:
        // a photo geotag is not a spatially-queried entity, so the "geometry lives
        // in DB 13" rule does not apply. exif_* holds GPS detected in the uploaded
        // image but NOT yet applied — it only powers the opt-in "add location?"
        // prompt and is never surfaced until the user copies it into latitude/
        // longitude.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE profile_photos (
                id             UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id        UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                document_id    UUID         NOT NULL,                 -- References DB 11 (Documents) documents.id
                caption        VARCHAR(140) NULL,
                description    TEXT         NULL,
                tags           JSONB        NOT NULL DEFAULT '[]',
                latitude       NUMERIC(9,6) NULL,
                longitude      NUMERIC(9,6) NULL,
                location_name  VARCHAR(160) NULL,
                exif_latitude  NUMERIC(9,6) NULL,                     -- detected in image, NOT applied
                exif_longitude NUMERIC(9,6) NULL,                     -- detected in image, NOT applied
                sort_order     INT          NOT NULL DEFAULT 0,
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at     TIMESTAMPTZ  NULL
            );

            CREATE UNIQUE INDEX uq_profile_photos_document
                ON profile_photos (document_id) WHERE deleted_at IS NULL;
            CREATE INDEX idx_profile_photos_user_order
                ON profile_photos (user_id, sort_order) WHERE deleted_at IS NULL;

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON profile_photos
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);

        // Backfill a metadata row for every existing profile photo so current
        // galleries keep rendering. Documents live on a different connection, so
        // this is assembled in PHP (no cross-DB SQL) and inserted in created_at
        // order to seed a stable initial sort_order.
        $existing = DB::connection('documents')->table('documents')
            ->where('document_type', 'profile_photo')
            ->whereNull('deleted_at')
            ->orderBy('owner_user_id')
            ->orderBy('created_at')
            ->get(['id', 'owner_user_id', 'created_at']);

        $orderByUser = [];
        $rows = [];
        foreach ($existing as $doc) {
            $orderByUser[$doc->owner_user_id] = ($orderByUser[$doc->owner_user_id] ?? -1) + 1;
            $rows[] = [
                'id'          => (string) Str::uuid(),
                'user_id'     => $doc->owner_user_id,
                'document_id' => $doc->id,
                'tags'        => '[]',
                'sort_order'  => $orderByUser[$doc->owner_user_id],
                'created_at'  => $doc->created_at,
                'updated_at'  => $doc->created_at,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection($this->connection)->table('profile_photos')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS profile_photos CASCADE');
    }
};
