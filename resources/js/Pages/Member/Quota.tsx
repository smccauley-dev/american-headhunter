import { Head } from '@inertiajs/react'

interface Quota {
  species: string
  max_harvest: number
  current_harvest: number
  remaining: number | null
  scope: 'lease' | 'property'
}

interface LeaseGroup {
  lease_id: string
  property_title: string
  quotas: Quota[]
}

interface Props {
  season_year: number
  leases: LeaseGroup[]
  harvest_url: string
}

const INK = '#0a1512'
const BLAZE = '#c84c21'
const MONO = "'JetBrains Mono', Menlo, monospace"
const DISPLAY = "'Fraunces', Georgia, serif"

function remainingColor(q: Quota): string {
  if (q.remaining === null) return '#6b5e50'
  if (q.remaining <= 0) return '#b91c1c'
  if (q.max_harvest > 0 && q.remaining / q.max_harvest <= 0.25) return '#b45309'
  return '#15803d'
}

export default function Quota({ season_year, leases, harvest_url }: Props) {
  const hasAny = leases.some(l => l.quotas.length > 0)

  return (
    <>
      <Head title="Harvest Quotas" />
      <div style={{ minHeight: '100vh', background: '#faf7f2' }}>
        <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '640px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: BLAZE, fontWeight: 700 }}>American Headhunter</span>
            <a href={harvest_url} style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>Harvest Log</a>
          </div>
        </div>

        <div style={{ maxWidth: '640px', margin: '0 auto', padding: '40px 16px 64px' }}>
          <div style={{ marginBottom: '24px' }}>
            <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>Season {season_year}</div>
            <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>Harvest Quotas</h1>
          </div>

          {!hasAny ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '32px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>No quotas set</div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: 0 }}>
                None of your active leases have a harvest quota for the {season_year} season. You can log harvests without a limit until a landowner sets one.
              </p>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
              {leases.filter(l => l.quotas.length > 0).map(group => (
                <div key={group.lease_id} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', overflow: 'hidden' }}>
                  <div style={{ padding: '14px 20px', borderBottom: '1px solid #f0ece6', background: '#fafaf9' }}>
                    <div style={{ fontFamily: DISPLAY, fontSize: '17px', color: INK }}>{group.property_title}</div>
                  </div>
                  <div>
                    {group.quotas.map((q, i) => (
                      <div key={i} style={{ padding: '13px 20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: i === 0 ? 'none' : '1px solid #f4f1ec' }}>
                        <div>
                          <div style={{ fontFamily: DISPLAY, fontSize: '15px', color: INK }}>{q.species}</div>
                          <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.08em', textTransform: 'uppercase', color: '#a89874', marginTop: '2px' }}>{q.scope} quota</div>
                        </div>
                        <div style={{ textAlign: 'right' }}>
                          <div style={{ fontFamily: MONO, fontSize: '15px', fontWeight: 700, color: remainingColor(q) }}>
                            {q.remaining ?? '—'} left
                          </div>
                          <div style={{ fontFamily: MONO, fontSize: '11px', color: '#6b5e50', marginTop: '2px' }}>
                            {q.current_harvest} / {q.max_harvest} taken
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
