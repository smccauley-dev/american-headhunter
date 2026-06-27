import { useForm } from '@inertiajs/react'
import { PortalChrome, PropertyHead, Section, BackLink, TabBar, useTabQuery, TrophyIcon, ClipboardListIcon, SparklesIcon, MapPinIcon, PhotoIcon, MapIcon, UserGroupIcon, UsersIcon, INK, ACCENT, type PropertySummary } from '@/Components/Member/PropertyChrome'
import PropertyPhotosTab, { type Photo } from '@/Components/Member/PropertyPhotosTab'
import PropertyMapTab, { type MapImage, type DeletedMapImage } from '@/Components/Member/PropertyMapTab'
import PropertyTeamTab, { type Manager } from '@/Components/Member/PropertyTeamTab'
import PropertyContactsTab, { type ContactDirectory, type EligibleManager, type EditableContact } from '@/Components/Member/PropertyContactsTab'

const TABS = [
  { key: 'game_type', label: 'Game Type', icon: <TrophyIcon /> },
  { key: 'rules', label: 'Property Rules', icon: <ClipboardListIcon /> },
  { key: 'amenities', label: 'Amenities', icon: <SparklesIcon /> },
  { key: 'photos', label: 'Photos', icon: <PhotoIcon /> },
  { key: 'map', label: 'Map', icon: <MapIcon /> },
  { key: 'checkin', label: 'Check In/Out', icon: <MapPinIcon /> },
  { key: 'team', label: 'Team', icon: <UserGroupIcon /> },
  { key: 'contacts', label: 'Contacts', icon: <UsersIcon /> },
]

const DETAIL_TABS = ['game_type', 'rules', 'amenities']

interface SpeciesRow { species_code: string; is_primary: boolean }
interface RuleRow { rule_text: string }
interface AmenityItem { id: string; name: string }
interface AmenityGroup { category: string; label: string; items: AmenityItem[] }
interface CheckIn {
  name: string; email: string; lease_ref: string
  checked_in_at: string | null; checked_out_at: string | null; open: boolean
}

interface Props {
  property: PropertySummary & { id: string }
  species: SpeciesRow[]
  rules: RuleRow[]
  amenityIds: string[]
  speciesOptions: Record<string, string>
  amenityCatalog: AmenityGroup[]
  photos: Photo[]
  mapImages: MapImage[]
  deletedMapImages: DeletedMapImage[]
  markerTypes: Record<string, string>
  markerColors: Record<string, string>
  checkIns: CheckIn[]
  managers: Manager[]
  roles: Record<string, string>
  contactDirectory: ContactDirectory
  eligibleManagers: EligibleManager[]
  editableContacts: EditableContact[]
  contactTypes: Record<string, string>
}

const input: React.CSSProperties = {
  width: '100%',
  fontFamily: 'Crimson Pro, Georgia, serif',
  fontSize: '15px',
  color: INK,
  background: '#fff',
  border: '1px solid #d4c9b0',
  padding: '9px 11px',
  outline: 'none',
  boxSizing: 'border-box',
}

const ghostBtn: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '9px',
  fontWeight: 700,
  letterSpacing: '.08em',
  textTransform: 'uppercase',
  padding: '7px 12px',
  background: 'transparent',
  color: INK,
  border: '1px solid #d4c9b0',
  cursor: 'pointer',
}

const addBtn: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  fontWeight: 700,
  letterSpacing: '.1em',
  textTransform: 'uppercase',
  padding: '9px 18px',
  background: 'transparent',
  color: ACCENT,
  border: `1px solid rgba(200,76,33,0.4)`,
  cursor: 'pointer',
  marginTop: '4px',
}

export default function PropertyDetails(props: Props) {
  const {
    property, species, rules, amenityIds, speciesOptions, amenityCatalog,
    photos, mapImages, deletedMapImages, markerTypes, markerColors, checkIns, managers, roles,
    contactDirectory, eligibleManagers, editableContacts, contactTypes,
  } = props

  const { data, setData, put, processing, recentlySuccessful, errors } = useForm({
    species: species.length ? species : [] as SpeciesRow[],
    rules: rules.length ? rules : [] as RuleRow[],
    amenity_ids: amenityIds,
  })

  const [tab, setTab] = useTabQuery('game_type')
  const speciesCodes = Object.keys(speciesOptions)

  function submit(e: React.FormEvent) {
    e.preventDefault()
    put(`/member/properties/${property.id}/details`, { preserveScroll: true })
  }

  // ── Species helpers ──────────────────────────────────────────────────────
  function addSpecies() {
    setData('species', [...data.species, { species_code: speciesCodes[0], is_primary: false }])
  }
  function setSpecies(i: number, patch: Partial<SpeciesRow>) {
    setData('species', data.species.map((s, idx) => idx === i ? { ...s, ...patch } : s))
  }
  function removeSpecies(i: number) {
    setData('species', data.species.filter((_, idx) => idx !== i))
  }

  // ── Rules helpers ────────────────────────────────────────────────────────
  function addRule() {
    setData('rules', [...data.rules, { rule_text: '' }])
  }
  function setRule(i: number, text: string) {
    setData('rules', data.rules.map((r, idx) => idx === i ? { rule_text: text } : r))
  }
  function removeRule(i: number) {
    setData('rules', data.rules.filter((_, idx) => idx !== i))
  }
  function moveRule(i: number, dir: -1 | 1) {
    const j = i + dir
    if (j < 0 || j >= data.rules.length) return
    const next = [...data.rules]
    ;[next[i], next[j]] = [next[j], next[i]]
    setData('rules', next)
  }

  // ── Amenity helpers ──────────────────────────────────────────────────────
  function toggleAmenity(id: string) {
    setData('amenity_ids', data.amenity_ids.includes(id)
      ? data.amenity_ids.filter(a => a !== id)
      : [...data.amenity_ids, id])
  }

  return (
    <PortalChrome headTitle={`Details · ${property.title}`}>

      <BackLink href={`/member/properties/${property.id}`}>← Back to Property</BackLink>

      <PropertyHead property={property} />

      <TabBar tabs={TABS} active={tab} onChange={setTab} />

      <form onSubmit={submit}>

        {/* Game Type */}
        {tab === 'game_type' && (
        <Section title="Game Type" icon={<TrophyIcon />} description="The huntable species offered on this property.">
          {data.species.length === 0 && (
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: '0 0 14px' }}>
              No game types added yet.
            </p>
          )}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {data.species.map((s, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                <select value={s.species_code} onChange={e => setSpecies(i, { species_code: e.target.value })} style={{ ...input, flex: 1 }}>
                  {speciesCodes.map(code => <option key={code} value={code}>{speciesOptions[code]}</option>)}
                </select>
                <label style={{ display: 'flex', alignItems: 'center', gap: '7px', cursor: 'pointer', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', textTransform: 'uppercase', color: '#6b5e50', whiteSpace: 'nowrap' }}>
                  <input type="checkbox" checked={s.is_primary} onChange={e => setSpecies(i, { is_primary: e.target.checked })} />
                  Primary
                </label>
                <button type="button" onClick={() => removeSpecies(i)} style={{ ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }}>Remove</button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addSpecies} style={addBtn}>+ Add Game Type</button>
        </Section>
        )}

        {/* Property Rules */}
        {tab === 'rules' && (
        <Section title="Property Rules" icon={<ClipboardListIcon />} description="Rules every hunter must follow on this property.">
          {data.rules.length === 0 && (
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: '0 0 14px' }}>
              No rules added yet.
            </p>
          )}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {data.rules.map((r, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: '10px' }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                  <button type="button" onClick={() => moveRule(i, -1)} disabled={i === 0} style={{ ...ghostBtn, padding: '4px 9px', opacity: i === 0 ? 0.35 : 1 }}>↑</button>
                  <button type="button" onClick={() => moveRule(i, 1)} disabled={i === data.rules.length - 1} style={{ ...ghostBtn, padding: '4px 9px', opacity: i === data.rules.length - 1 ? 0.35 : 1 }}>↓</button>
                </div>
                <div style={{ flex: 1 }}>
                  <textarea rows={2} value={r.rule_text} onChange={e => setRule(i, e.target.value)} maxLength={500} style={{ ...input, resize: 'vertical', lineHeight: 1.5 }} placeholder="No ATVs after dark, sign the gate log on entry, …" />
                  {errors[`rules.${i}.rule_text` as keyof typeof errors] && (
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>Rule text is required.</div>
                  )}
                </div>
                <button type="button" onClick={() => removeRule(i)} style={{ ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }}>Remove</button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addRule} style={addBtn}>+ Add Rule</button>
        </Section>
        )}

        {/* Amenities */}
        {tab === 'amenities' && (
        <Section title="Amenities" icon={<SparklesIcon />} description="Features and facilities available on the property, grouped by category.">
          {amenityCatalog.length === 0 ? (
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
              No amenities are configured on the platform yet.
            </p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
              {amenityCatalog.map(group => (
                <div key={group.category}>
                  <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '16px', color: INK, marginBottom: '10px' }}>{group.label}</div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 16px' }}>
                    {group.items.map(a => (
                      <label key={a.id} style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
                        <input type="checkbox" checked={data.amenity_ids.includes(a.id)} onChange={() => toggleAmenity(a.id)} />
                        {a.name}
                      </label>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Section>
        )}

        {/* Actions — only for the form-backed detail tabs */}
        {DETAIL_TABS.includes(tab) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <button type="submit" disabled={processing} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: INK, color: '#F4ECDC', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
            {processing ? 'Saving…' : 'Save Details'}
          </button>
          <a href={`/member/properties/${property.id}`} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', textDecoration: 'none' }}>
            Back
          </a>
          {recentlySuccessful && (
            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#4a7c59' }}>
              Saved ✓
            </span>
          )}
        </div>
        )}

      </form>

      {/* Photos */}
      {tab === 'photos' && <PropertyPhotosTab propertyId={property.id} photos={photos} />}

      {/* Map */}
      {tab === 'map' && <PropertyMapTab propertyId={property.id} images={mapImages} deletedImages={deletedMapImages} markerTypes={markerTypes} markerColors={markerColors} />}

      {/* Check In/Out */}
      {tab === 'checkin' && (
        <Section title="Check-In Log" icon={<MapPinIcon />} description="A running record of every hunter check-in and check-out on this property, across all leases. Newest first.">
          {checkIns.length === 0 ? (
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
              No check-ins recorded on this property yet.
            </p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              {checkIns.map((c, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', padding: '11px 0', borderTop: i === 0 ? 'none' : '1px solid #e5ddd0' }}>
                  <div>
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
                      {c.name}
                      <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#a89874', marginLeft: '8px' }}>#{c.lease_ref}</span>
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b5e50', marginTop: '3px' }}>
                      In {c.checked_in_at ?? '—'}{c.checked_out_at ? ` · Out ${c.checked_out_at}` : ''}
                    </div>
                  </div>
                  {c.open && (
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', borderRadius: '9999px', background: '#065f46', color: '#fff', flexShrink: 0 }}>
                      On Site
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Team (managers) */}
      {tab === 'team' && <PropertyTeamTab propertyId={property.id} managers={managers} roles={roles} />}

      {/* Contacts */}
      {tab === 'contacts' && (
        <PropertyContactsTab
          propertyId={property.id}
          directory={contactDirectory}
          eligibleManagers={eligibleManagers}
          contacts={editableContacts}
          contactTypes={contactTypes}
        />
      )}

    </PortalChrome>
  )
}
