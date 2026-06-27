<?php

namespace App\Support;

/**
 * Single source of truth for the controlled vocabulary a hunter may apply to a
 * profile-gallery photo. Mirrors the SPECIES / TERRAIN / SEASONS lists in
 * resources/js/Pages/Member/Profile/Hunter.tsx — keep the two in sync.
 *
 * Tags are a flat set of keys; the same key (e.g. small_game) appearing in two
 * source lists collapses to a single tag, so a key is emitted by the first
 * group that claims it and skipped thereafter.
 */
class PhotoTagVocabulary
{
    /** @var array<string, array{label: string, tags: array<string, string>}> */
    private const GROUPS = [
        'species' => [
            'label' => 'Species',
            'tags'  => [
                'whitetail'  => 'Whitetail',
                'mule_deer'  => 'Mule Deer',
                'elk'        => 'Elk',
                'turkey'     => 'Turkey',
                'hog'        => 'Wild Hog',
                'black_bear' => 'Black Bear',
                'waterfowl'  => 'Waterfowl',
                'dove'       => 'Dove',
                'quail'      => 'Quail',
                'pheasant'   => 'Pheasant',
                'small_game' => 'Small Game',
                'coyote'     => 'Predator',
            ],
        ],
        'terrain' => [
            'label' => 'Terrain',
            'tags'  => [
                'bottom_land'    => 'Bottom Land',
                'hardwood_ridge' => 'Hardwood Ridge',
                'open_field'     => 'Open Field',
                'brush_country'  => 'Brush Country',
                'wetlands'       => 'Wetlands',
                'river'          => 'River / Creek',
                'agricultural'   => 'Agricultural',
                'mountains'      => 'Mountains',
            ],
        ],
        'season' => [
            'label' => 'Season',
            'tags'  => [
                'archery'          => 'Archery',
                'muzzleloader'     => 'Muzzleloader',
                'rifle'            => 'Rifle',
                'spring_turkey'    => 'Spring Turkey',
                'waterfowl_season' => 'Waterfowl Season',
                'small_game'       => 'Small Game',
            ],
        ],
    ];

    /**
     * Grouped vocabulary for the client, with duplicate keys removed so each
     * tag chip renders exactly once.
     *
     * @return list<array{key: string, label: string, tags: list<array{key: string, label: string}>}>
     */
    public static function forClient(): array
    {
        $seen   = [];
        $groups = [];

        foreach (self::GROUPS as $groupKey => $group) {
            $tags = [];
            foreach ($group['tags'] as $key => $label) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $tags[]     = ['key' => $key, 'label' => $label];
            }
            $groups[] = ['key' => $groupKey, 'label' => $group['label'], 'tags' => $tags];
        }

        return $groups;
    }

    /**
     * Flat set of every valid tag key — used to validate submitted tags.
     *
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['tags'] as $key => $label) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Keep only valid keys from a submitted list, de-duplicated and re-indexed.
     *
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public static function sanitize(array $tags): array
    {
        $allowed = array_flip(self::allowedKeys());

        $clean = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && isset($allowed[$tag])) {
                $clean[$tag] = true;
            }
        }

        return array_keys($clean);
    }
}
