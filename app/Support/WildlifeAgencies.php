<?php

namespace App\Support;

class WildlifeAgencies
{
    /**
     * Two-letter USPS code => the state agency that sets hunting seasons and bag
     * limits. Used to name the right authority in the public "verify your local
     * regulations" disclaimer on a property's detail page.
     */
    public static function names(): array
    {
        return [
            'AL' => 'Alabama Department of Conservation and Natural Resources',
            'AK' => 'Alaska Department of Fish and Game',
            'AZ' => 'Arizona Game and Fish Department',
            'AR' => 'Arkansas Game and Fish Commission',
            'CA' => 'California Department of Fish and Wildlife',
            'CO' => 'Colorado Parks and Wildlife',
            'CT' => 'Connecticut Department of Energy and Environmental Protection',
            'DE' => 'Delaware Division of Fish and Wildlife',
            'FL' => 'Florida Fish and Wildlife Conservation Commission',
            'GA' => 'Georgia Wildlife Resources Division',
            'HI' => 'Hawaii Division of Forestry and Wildlife',
            'ID' => 'Idaho Department of Fish and Game',
            'IL' => 'Illinois Department of Natural Resources',
            'IN' => 'Indiana Division of Fish & Wildlife',
            'IA' => 'Iowa Department of Natural Resources',
            'KS' => 'Kansas Department of Wildlife and Parks',
            'KY' => 'Kentucky Department of Fish and Wildlife Resources',
            'LA' => 'Louisiana Department of Wildlife and Fisheries',
            'ME' => 'Maine Department of Inland Fisheries and Wildlife',
            'MD' => 'Maryland Department of Natural Resources',
            'MA' => 'Massachusetts Division of Fisheries and Wildlife',
            'MI' => 'Michigan Department of Natural Resources',
            'MN' => 'Minnesota Department of Natural Resources',
            'MS' => 'Mississippi Department of Wildlife, Fisheries, and Parks',
            'MO' => 'Missouri Department of Conservation',
            'MT' => 'Montana Fish, Wildlife & Parks',
            'NE' => 'Nebraska Game and Parks Commission',
            'NV' => 'Nevada Department of Wildlife',
            'NH' => 'New Hampshire Fish and Game Department',
            'NJ' => 'New Jersey Division of Fish and Wildlife',
            'NM' => 'New Mexico Department of Game and Fish',
            'NY' => 'New York State Department of Environmental Conservation',
            'NC' => 'North Carolina Wildlife Resources Commission',
            'ND' => 'North Dakota Game and Fish Department',
            'OH' => 'Ohio Division of Wildlife',
            'OK' => 'Oklahoma Department of Wildlife Conservation',
            'OR' => 'Oregon Department of Fish and Wildlife',
            'PA' => 'Pennsylvania Game Commission',
            'RI' => 'Rhode Island Division of Fish and Wildlife',
            'SC' => 'South Carolina Department of Natural Resources',
            'SD' => 'South Dakota Game, Fish and Parks',
            'TN' => 'Tennessee Wildlife Resources Agency',
            'TX' => 'Texas Parks and Wildlife Department',
            'UT' => 'Utah Division of Wildlife Resources',
            'VT' => 'Vermont Fish & Wildlife Department',
            'VA' => 'Virginia Department of Wildlife Resources',
            'WA' => 'Washington Department of Fish and Wildlife',
            'WV' => 'West Virginia Division of Natural Resources',
            'WI' => 'Wisconsin Department of Natural Resources',
            'WY' => 'Wyoming Game and Fish Department',
            'DC' => 'District of Columbia Department of Energy & Environment',
        ];
    }

    /** Agency name for a state code, or null when the code is unknown. */
    public static function forState(?string $stateCode): ?string
    {
        return $stateCode ? (self::names()[strtoupper($stateCode)] ?? null) : null;
    }
}
