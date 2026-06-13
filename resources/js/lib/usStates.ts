// Single source of truth for US states across the front-end.
// Mirrors the App\Support\UsStates PHP helper used on the server — keep the two
// in sync. Use this anywhere a state field (select, label, lookup) is needed
// instead of redefining a local list.

// Two-letter USPS code => full state name.
export const US_STATE_NAMES: Record<string, string> = {
  AL: 'Alabama',       AK: 'Alaska',         AZ: 'Arizona',        AR: 'Arkansas',      CA: 'California',
  CO: 'Colorado',      CT: 'Connecticut',    DE: 'Delaware',       FL: 'Florida',       GA: 'Georgia',
  HI: 'Hawaii',        ID: 'Idaho',          IL: 'Illinois',       IN: 'Indiana',       IA: 'Iowa',
  KS: 'Kansas',        KY: 'Kentucky',       LA: 'Louisiana',      ME: 'Maine',         MD: 'Maryland',
  MA: 'Massachusetts', MI: 'Michigan',       MN: 'Minnesota',      MS: 'Mississippi',   MO: 'Missouri',
  MT: 'Montana',       NE: 'Nebraska',       NV: 'Nevada',         NH: 'New Hampshire', NJ: 'New Jersey',
  NM: 'New Mexico',    NY: 'New York',       NC: 'North Carolina', ND: 'North Dakota',  OH: 'Ohio',
  OK: 'Oklahoma',      OR: 'Oregon',         PA: 'Pennsylvania',   RI: 'Rhode Island',  SC: 'South Carolina',
  SD: 'South Dakota',  TN: 'Tennessee',      TX: 'Texas',          UT: 'Utah',          VT: 'Vermont',
  VA: 'Virginia',      WA: 'Washington',     WV: 'West Virginia',  WI: 'Wisconsin',     WY: 'Wyoming',
}

// Bare list of two-letter codes, in display order.
export const US_STATE_CODES: string[] = Object.keys(US_STATE_NAMES)

// [code, name] tuples for building <option> lists.
export const US_STATES: [string, string][] = Object.entries(US_STATE_NAMES)
