import { useForm } from '@inertiajs/react'
import {
  PortalChrome, PropertyHead, Section, BackLink, TabBar, useTabQuery,
  INK, ACCENT, TAN, fieldInput as input, fiPrimaryBtn, fiGhostBtn, type PropertySummary,
} from '@/Components/Member/PropertyChromeV2'
import PropertyPhotosTab, { type Photo } from '@/Components/Member/PropertyPhotosTab'
import PropertyMapTab, { type MapImage, type DeletedMapImage } from '@/Components/Member/PropertyMapTab'
import PropertyTeamTab, { type Manager } from '@/Components/Member/PropertyTeamTab'
import PropertyContactsTab, { type ContactDirectory, type EligibleManager, type EditableContact } from '@/Components/Member/PropertyContactsTab'

const TABS = [
  { key: 'game_type', label: 'Game Type' },
  { key: 'rules', label: 'Property Rules' },
  { key: 'amenities', label: 'Amenities' },
  { key: 'photos', label: 'Photos' },
  { key: 'map', label: 'Map' },
  { key: 'checkin', label: 'Check In/Out' },
  { key: 'team', label: 'Team' },
  { key: 'contacts', label: 'Contacts' },
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

// ── Parity local styles (mono chrome, parchment ghosts) ──────────────────────
const emptyCopy: React.CSSProperties = { fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: '0 0 14px' }

const ghostBtn: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 500, letterSpacing: '.12em',
  textTransform: 'uppercase', padding: '8px 12px', background: '#fafafa',
  color: 'rgba(10,21,18,0.65)', border: '1px solid rgba(10,21,18,0.2)', cursor: 'pointer',
}
const dangerGhost: React.CSSProperties = { ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }
const addBtn: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 500, letterSpacing: '.12em',
  textTransform: 'uppercase', padding: '9px 16px', background: '#fafafa',
  color: ACCENT, border: '1px solid rgba(200,76,33,0.4)', cursor: 'pointer', marginTop: '12px',
}
const mutedMono: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.06em',
  textTransform: 'uppercase', color: '#6b5e50',
}

export default function PropertyDetailsV2(props: Props) {
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
        <Section title="Game Type" description="The species this property is managed and listed for. Mark one as the primary draw.">
          {data.species.length === 0 && (
            <p style={emptyCopy}>No game types added yet.</p>
          )}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {data.species.map((s, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                <select value={s.species_code} onChange={e => setSpecies(i, { species_code: e.target.value })} style={{ ...input, flex: 1 }}>
                  {speciesCodes.map(code => <option key={code} value={code}>{speciesOptions[code]}</option>)}
                </select>
                <label style={{ ...mutedMono, display: 'flex', alignItems: 'center', gap: '7px', cursor: 'pointer', whiteSpace: 'nowrap' }}>
                  <input type="checkbox" checked={s.is_primary} onChange={e => setSpecies(i, { is_primary: e.target.checked })} />
                  Primary
                </label>
                <button type="button" onClick={() => removeSpecies(i)} style={dangerGhost}>Remove</button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addSpecies} style={addBtn}>+ Add Game Type</button>
        </Section>
        )}

        {/* Property Rules */}
        {tab === 'rules' && (
        <Section title="Property Rules" description="House rules shown to hunters. Use the arrows to set the order they appear in.">
          {data.rules.length === 0 && (
            <p style={emptyCopy}>No rules added yet.</p>
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
                    <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>Rule text is required.</div>
                  )}
                </div>
                <button type="button" onClick={() => removeRule(i)} style={dangerGhost}>Remove</button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addRule} style={addBtn}>+ Add Rule</button>
        </Section>
        )}

        {/* Amenities */}
        {tab === 'amenities' && (
        <Section title="Amenities" description="Features and facilities available on this property.">
          {amenityCatalog.length === 0 ? (
            <p style={{ ...emptyCopy, margin: 0 }}>No amenities are configured on the platform yet.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
              {amenityCatalog.map(group => (
                <div key={group.category}>
                  <div style={{ fontFamily: 'var(--display), Georgia, serif', fontSize: '16px', color: INK, marginBottom: '10px' }}>{group.label}</div>
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
          <button type="submit" disabled={processing} style={{ ...fiPrimaryBtn, cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
            {processing ? 'Saving…' : 'Save Details'}
          </button>
          <a href={`/member/properties/${property.id}`} style={{ ...fiGhostBtn, textDecoration: 'none' }}>
            Back
          </a>
          {recentlySuccessful && (
            <span style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 500, letterSpacing: '.1em', textTransform: 'uppercase', color: '#4a7c59' }}>
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
        <Section title="Check-In Log" description="A running record of who has checked in and out on this property.">
          {checkIns.length === 0 ? (
            <p style={{ ...emptyCopy, margin: 0 }}>No check-ins recorded on this property yet.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              {checkIns.map((c, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', padding: '11px 0', borderTop: i === 0 ? 'none' : `1px solid rgba(10,21,18,0.12)` }}>
                  <div>
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
                      {c.name}
                      <span style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: TAN, marginLeft: '8px' }}>#{c.lease_ref}</span>
                    </div>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: '11px', color: '#6b5e50', marginTop: '3px' }}>
                      In {c.checked_in_at ?? '—'}{c.checked_out_at ? ` · Out ${c.checked_out_at}` : ''}
                    </div>
                  </div>
                  {c.open && (
                    <span style={{ fontFamily: 'var(--mono)', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', borderRadius: '9999px', background: '#065f46', color: '#fff', flexShrink: 0 }}>
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
