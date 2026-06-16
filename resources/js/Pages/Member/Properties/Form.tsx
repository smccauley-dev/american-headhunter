import { Head, useForm, router } from '@inertiajs/react'

interface PropertyData {
  id: string
  title: string
  description: string | null
  status: string
  state_code: string | null
  county: string | null
  center_lat: number | null
  center_lng: number | null
  total_acres: number | null
  huntable_acres: number | null
}

interface Props {
  property: PropertyData | null
  states: Record<string, string>
  statuses: Record<string, string>
}

// ── Design tokens (member portal) ───────────────────────────────────────────
const INK = '#0A1512'
const ACCENT = '#C84C21'
const PAPER = '#F8F4EB'

const label: React.CSSProperties = {
  display: 'block',
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  fontWeight: 600,
  letterSpacing: '.12em',
  textTransform: 'uppercase',
  color: '#a89874',
  marginBottom: '6px',
}

const input: React.CSSProperties = {
  width: '100%',
  fontFamily: 'Crimson Pro, Georgia, serif',
  fontSize: '16px',
  color: INK,
  background: '#fff',
  border: '1px solid #d4c9b0',
  padding: '10px 12px',
  outline: 'none',
  boxSizing: 'border-box',
}

const errStyle: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  color: ACCENT,
  marginTop: '5px',
}

function Field({ children, htmlFor, text, error }: { children: React.ReactNode; htmlFor: string; text: string; error?: string }) {
  return (
    <div>
      <label htmlFor={htmlFor} style={label}>{text}</label>
      {children}
      {error && <div style={errStyle}>{error}</div>}
    </div>
  )
}

export default function PropertyForm({ property, states, statuses }: Props) {
  const isEdit = property !== null

  const { data, setData, post, put, processing, errors, recentlySuccessful } = useForm({
    title:          property?.title          ?? '',
    description:    property?.description     ?? '',
    status:         property?.status         ?? 'draft',
    state_code:     property?.state_code     ?? '',
    county:         property?.county         ?? '',
    center_lat:     property?.center_lat != null ? String(property.center_lat) : '',
    center_lng:     property?.center_lng != null ? String(property.center_lng) : '',
    total_acres:    property?.total_acres != null ? String(property.total_acres) : '',
    huntable_acres: property?.huntable_acres != null ? String(property.huntable_acres) : '',
  })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    if (isEdit) {
      put(`/member/properties/${property!.id}`)
    } else {
      post('/member/properties')
    }
  }

  return (
    <>
      <Head title={isEdit ? `Edit · ${property!.title}` : 'New Property'} />

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
            <button
              onClick={() => router.post('/logout')}
              style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}
            >
              Sign Out
            </button>
          </div>
        </div>

        <div style={{ maxWidth: '760px', margin: '0 auto', padding: '36px 16px 64px' }}>

          {/* Breadcrumb */}
          <a
            href="/member/profile"
            style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', textDecoration: 'none', display: 'inline-block', marginBottom: '18px' }}
          >
            ← Back to Profile
          </a>

          {/* Heading */}
          <div style={{ marginBottom: '28px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
              {isEdit ? 'Edit Property' : 'New Property'}
            </div>
            <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
              {isEdit ? property!.title : 'Add a Property'}
            </h1>
          </div>

          <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: '22px', border: '1px solid #d4c9b0', background: '#FBF7EE', padding: '28px 26px' }}>

            <Field htmlFor="title" text="Property Name" error={errors.title}>
              <input id="title" type="text" value={data.title} onChange={e => setData('title', e.target.value)} style={input} placeholder="North Forty Hunting Tract" />
            </Field>

            <Field htmlFor="description" text="Description" error={errors.description}>
              <textarea id="description" rows={4} value={data.description} onChange={e => setData('description', e.target.value)} style={{ ...input, resize: 'vertical', lineHeight: 1.6 }} placeholder="Terrain, access, history, what makes this property special…" />
            </Field>

            <Field htmlFor="status" text="Status" error={errors.status}>
              <select id="status" value={data.status} onChange={e => setData('status', e.target.value)} style={input}>
                {Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
              <Field htmlFor="state_code" text="State" error={errors.state_code}>
                <select id="state_code" value={data.state_code} onChange={e => setData('state_code', e.target.value)} style={input}>
                  <option value="">— Select state —</option>
                  {Object.entries(states).map(([code, name]) => <option key={code} value={code}>{name}</option>)}
                </select>
              </Field>
              <Field htmlFor="county" text="County" error={errors.county}>
                <input id="county" type="text" value={data.county} onChange={e => setData('county', e.target.value)} style={input} placeholder="Walker" />
              </Field>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
              <Field htmlFor="total_acres" text="Total Acres" error={errors.total_acres}>
                <input id="total_acres" type="number" min={1} step="0.01" value={data.total_acres} onChange={e => setData('total_acres', e.target.value)} style={input} placeholder="640" />
              </Field>
              <Field htmlFor="huntable_acres" text="Huntable Acres" error={errors.huntable_acres}>
                <input id="huntable_acres" type="number" min={0} step="0.01" value={data.huntable_acres} onChange={e => setData('huntable_acres', e.target.value)} style={input} placeholder="580" />
              </Field>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
              <Field htmlFor="center_lat" text="Latitude" error={errors.center_lat}>
                <input id="center_lat" type="number" step="any" min={-90} max={90} value={data.center_lat} onChange={e => setData('center_lat', e.target.value)} style={input} placeholder="30.267153" />
              </Field>
              <Field htmlFor="center_lng" text="Longitude" error={errors.center_lng}>
                <input id="center_lng" type="number" step="any" min={-180} max={180} value={data.center_lng} onChange={e => setData('center_lng', e.target.value)} style={input} placeholder="-97.743057" />
              </Field>
            </div>
            <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#6b5e50', marginTop: '-14px' }}>
              WGS84 decimal degrees — a single map pin for the property. Negative longitude is West.
            </div>

            {/* Actions */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '12px', borderTop: '1px solid #e5ddd0', paddingTop: '20px', marginTop: '4px' }}>
              <button
                type="submit"
                disabled={processing}
                style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: INK, color: '#F4ECDC', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}
              >
                {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Property'}
              </button>
              <a
                href="/member/profile"
                style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', textDecoration: 'none' }}
              >
                Cancel
              </a>
              {recentlySuccessful && (
                <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#4a7c59' }}>
                  Saved ✓
                </span>
              )}
            </div>
          </form>

          {isEdit && (
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#6b5e50', marginTop: '20px', lineHeight: 1.5 }}>
              Listings, game types, photos, maps, rules, and contacts for this property are managed from the admin tools — coming to this page next.
            </p>
          )}

        </div>
      </div>
    </>
  )
}
