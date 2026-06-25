<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A callout used to carry a single CTA (cta_label / cta_url). Some callouts need
 * more than one button — e.g. the Veteran / First Responder banner wants one
 * button per service type, each deep-linking the signup form's service step.
 *
 * Replaces the single CTA columns with a `buttons` JSONB array of {label, url},
 * carrying any existing single CTA across, and re-points the seeded Veteran /
 * First Responder banner at two service-flagged buttons.
 */
return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE pricing_callouts
                ADD COLUMN buttons JSONB NOT NULL DEFAULT '[]';  -- [{label, url}]

            -- Carry any existing single CTA into the new buttons array.
            UPDATE pricing_callouts
            SET buttons = jsonb_build_array(jsonb_build_object('label', cta_label, 'url', cta_url))
            WHERE cta_label IS NOT NULL AND cta_label <> '';

            -- The seeded Veteran / First Responder banner gets two deep-linked
            -- buttons; each flags the signup form's service-status step so the
            -- matching document-upload section is preselected.
            UPDATE pricing_callouts
            SET buttons = jsonb_build_array(
                jsonb_build_object('label', 'Verify as Veteran',         'url', '/get-started?type=hunter&service=veteran'),
                jsonb_build_object('label', 'Verify as First Responder', 'url', '/get-started?type=hunter&service=first_responder')
            )
            WHERE account_type = 'hunter' AND eyebrow = 'Veteran or First Responder?';

            ALTER TABLE pricing_callouts DROP COLUMN cta_label;
            ALTER TABLE pricing_callouts DROP COLUMN cta_url;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE pricing_callouts ADD COLUMN cta_label VARCHAR(40);
            ALTER TABLE pricing_callouts ADD COLUMN cta_url   VARCHAR(255);

            -- Restore the first button into the single CTA columns.
            UPDATE pricing_callouts
            SET cta_label = buttons->0->>'label',
                cta_url   = buttons->0->>'url'
            WHERE jsonb_array_length(buttons) > 0;

            ALTER TABLE pricing_callouts DROP COLUMN buttons;
        SQL);
    }
};
