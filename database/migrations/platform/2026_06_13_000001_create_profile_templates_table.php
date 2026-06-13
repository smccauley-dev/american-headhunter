<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        // Default config — equals the current hard-coded profile appearance so seeding
        // produces no visual change. `order` and `theme` are honored from Slice 2 on.
        $default = json_encode([
            'decorations' => [
                'coffee_stain'       => ['enabled' => true, 'opacity' => 0.45],
                'registration_marks' => ['enabled' => true],
                'topo_background'    => ['enabled' => true],
            ],
            'modules' => [
                'about'    => ['enabled' => true, 'order' => 1],
                'contact'  => ['enabled' => true, 'order' => 2],
                'social'   => ['enabled' => true, 'order' => 3],
                'photos'   => ['enabled' => true, 'order' => 4],
                'gear'     => ['enabled' => true, 'order' => 5],
                'activity' => ['enabled' => true, 'order' => 6],
            ],
            'theme' => ['accent' => '#C84C21', 'paper' => '#F8F4EB', 'ink' => '#0A1512'],
        ]);

        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE profile_templates (
                id                   UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                profile_type         VARCHAR(30) NOT NULL,
                name                 VARCHAR(100) NOT NULL,
                description          TEXT,
                draft_config         JSONB NOT NULL DEFAULT '{}'::jsonb,
                published_config     JSONB NOT NULL DEFAULT '{}'::jsonb,
                published_at         TIMESTAMPTZ,
                published_by_user_id UUID, -- References DB 1 (Identity) users.id
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_profile_templates_type UNIQUE (profile_type)
            );

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON profile_templates
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            INSERT INTO profile_templates (profile_type, name, description, draft_config, published_config, published_at) VALUES
                ('hunter',    'Hunter Profile',    'Default template for hunter member profiles.',    '{$default}'::jsonb, '{$default}'::jsonb, NOW()),
                ('angler',    'Angler Profile',    'Default template for angler member profiles.',    '{$default}'::jsonb, '{$default}'::jsonb, NOW()),
                ('outfitter', 'Outfitter Profile', 'Default template for outfitter member profiles.', '{$default}'::jsonb, '{$default}'::jsonb, NOW());
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS profile_templates CASCADE');
    }
};
