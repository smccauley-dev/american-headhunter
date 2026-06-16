import { Head, useForm, router } from '@inertiajs/react'

interface SpeciesRow { species_code: string; is_primary: boolean }
interface RuleRow { rule_text: string }
interface AmenityItem { id: string; name: string }
interface AmenityGroup { category: string; label: string; items: AmenityItem[] }

interface Props {
  property: { id: string; title: string }
  species: SpeciesRow[]
  rules: RuleRow[]
  amenityIds: string[]
  speciesOptions: Record<string, string>
  amenityCatalog: AmenityGroup[]
}

// ── Design tokens ───────────────────────────────────────────────────────────
const INK = '#0A1512'
const ACCENT = '#C84C21'
const PAPER = '#F8F4EB'

const sectionLabel: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '11px',
  fontWeight: 700,
  letterSpacing: '.14em',
  textTransform: 'uppercase',
  color: '#a89874',
  marginBottom: '14px',
}

const cardStyle: React.CSSProperties = {
  border: '1px solid #d4c9b0',
  background: '#FBF7EE',
  padding: '24px 22px',
  marginBottom: '22px',
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

export default function PropertyDetails({ property, species, rules, amenityIds, speciesOptions, amenityCatalog }: Props) {
  const { data, setData, put, processing, recentlySuccessful, errors } = useForm({
    species: species.length ? species : [] as SpeciesRow[],
    rules: rules.length ? rules : [] as RuleRow[],
    amenity_ids: amenityIds,
  })

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
    <>
      <Head title={`Details · ${property.title}`} />

      <div style={{ minHeight: '100vh', background: PAPER }}>

        {/* Topbar */}
        <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '760px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: ACCENT, fontWeight: 700 }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Property Management
              </span>
            </div>
            <button onClick={() => router.post('/logout')} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}>
              Sign Out
            </button>
          </div>
        </div>

        <form onSubmit={submit} style={{ maxWidth: '760px', margin: '0 auto', padding: '36px 16px 64px' }}>

          <a href={`/member/properties/${property.id}`} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', textDecoration: 'none', display: 'inline-block', marginBottom: '18px' }}>
            ← {property.title}
          </a>

          <div style={{ marginBottom: '28px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
              Property Details
            </div>
            <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
              {property.title}
            </h1>
          </div>

          {/* Game Type */}
          <div style={cardStyle}>
            <div style={sectionLabel}>Game Type</div>
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
          </div>

          {/* Property Rules */}
          <div style={cardStyle}>
            <div style={sectionLabel}>Property Rules</div>
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
          </div>

          {/* Amenities */}
          <div style={cardStyle}>
            <div style={sectionLabel}>Amenities</div>
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
          </div>

          {/* Actions */}
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

        </form>
      </div>
    </>
  )
}
