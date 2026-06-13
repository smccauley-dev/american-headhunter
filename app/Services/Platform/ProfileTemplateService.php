<?php

namespace App\Services\Platform;

use App\Models\Platform\ProfileTemplate;
use App\Services\BaseService;

class ProfileTemplateService extends BaseService
{
    /** Public profile types that have a template. */
    public const TYPES = ['hunter', 'angler', 'outfitter'];

    /**
     * Default config — the baseline merged under every stored config so adding a new
     * decoration/module key in code never breaks existing rows. Equals the original
     * hard-coded profile appearance. `order` and `theme` are honored from Slice 2 on.
     */
    public const DEFAULT_TEMPLATE = [
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
    ];

    /**
     * Resolved published config for a profile type — defaults deep-merged with the
     * stored published_config. Cached in Valkey Cluster 2; invalidated on publish.
     * Unknown types fall back to the bare defaults.
     */
    public function getPublishedConfig(string $profileType): array
    {
        if (! in_array($profileType, self::TYPES, true)) {
            return self::DEFAULT_TEMPLATE;
        }

        return $this->cache("cfg:profile_template:{$profileType}", function () use ($profileType) {
            $row = ProfileTemplate::on('platform')->where('profile_type', $profileType)->first();

            return $this->mergeDefaults($row?->published_config ?? []);
        }, ttlMinutes: 60);
    }

    /** Raw draft config (defaults-merged) for the admin editor. */
    public function getDraftConfig(string $profileType): array
    {
        $row = ProfileTemplate::on('platform')->where('profile_type', $profileType)->first();

        return $this->mergeDefaults($row?->draft_config ?? []);
    }

    /** Save the working draft without affecting the live profile. */
    public function saveDraft(string $profileType, array $config): void
    {
        ProfileTemplate::on('platform')
            ->where('profile_type', $profileType)
            ->update(['draft_config' => $this->mergeDefaults($config)]);
    }

    /** Promote the current draft to live and invalidate the published cache. */
    public function publish(string $profileType, ?string $userId = null): void
    {
        $row = ProfileTemplate::on('platform')->where('profile_type', $profileType)->first();
        if (! $row) {
            return;
        }

        $row->published_config     = $this->mergeDefaults($row->draft_config ?? []);
        $row->published_at         = now();
        $row->published_by_user_id = $userId;
        $row->save();

        $this->invalidate("cfg:profile_template:{$profileType}");
    }

    /**
     * Deep-merge stored config over DEFAULT_TEMPLATE so missing keys are backfilled
     * and unknown keys are dropped from the top-level shape we care about.
     */
    private function mergeDefaults(array $config): array
    {
        return [
            'decorations' => $this->mergeSection(self::DEFAULT_TEMPLATE['decorations'], $config['decorations'] ?? []),
            'modules'     => $this->mergeSection(self::DEFAULT_TEMPLATE['modules'], $config['modules'] ?? []),
            'theme'       => array_merge(self::DEFAULT_TEMPLATE['theme'], $config['theme'] ?? []),
        ];
    }

    /** Merge a {key: {settings}} section, keeping only keys present in defaults. */
    private function mergeSection(array $defaults, array $stored): array
    {
        $out = [];
        foreach ($defaults as $key => $defSettings) {
            $out[$key] = array_merge($defSettings, $stored[$key] ?? []);
        }

        return $out;
    }
}
