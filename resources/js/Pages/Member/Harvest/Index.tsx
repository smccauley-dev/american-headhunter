import { Head, router, usePage } from '@inertiajs/react'
import OfflineQueueBanner from '@/Components/Member/OfflineQueueBanner'
import MemberTopbar from '@/Components/Member/MemberTopbar'

interface Harvest {
  id: string
  species: string
  weapon: string
  harvest_date: string | null
  property_title: string
  antler_score: string | number | null
  is_public: boolean
  photo_urls: string[]
}

interface Props {
  harvests: Harvest[]
  new_url: string
  quota_url: string
}

interface PageProps {
  flash: { success: string | null; error: string | null }
  [key: string]: unknown
}

const INK = '#0a1512'
const BLAZE = '#c84c21'
const MONO = "'JetBrains Mono', Menlo, monospace"
const DISPLAY = "'Fraunces', Georgia, serif"

export default function HarvestIndex({ harvests, new_url, quota_url }: Props) {
  const { flash } = usePage<PageProps>().props

  return (
    <>
      <Head title="Harvest Log" />
      <div className="topo-bg" style={{ minHeight: '100vh', backgroundColor: '#EDE5D0' }}>
        <MemberTopbar />
        <div style={{ maxWidth: '640px', margin: '0 auto', padding: '40px 16px 64px' }}>

          {flash?.success && (
            <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: '4px', padding: '12px 16px', marginBottom: '16px', fontSize: '13px', color: '#15803d' }}>
              {flash.success}
            </div>
          )}
          {flash?.error && (
            <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '4px', padding: '12px 16px', marginBottom: '16px', fontSize: '13px', color: '#b91c1c' }}>
              {flash.error}
            </div>
          )}

          <OfflineQueueBanner />

          {/* Header */}
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '24px', gap: '12px', flexWrap: 'wrap' }}>
            <div>
              <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
                Field Log
              </div>
              <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
                Harvest Log
              </h1>
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <a href={quota_url} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, textDecoration: 'none', border: '1px solid #d4c9b0', padding: '10px 16px' }}>
                Quotas
              </a>
              <button onClick={() => router.visit(new_url)} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#fff', background: BLAZE, border: 'none', padding: '10px 16px', cursor: 'pointer' }}>
                Log Harvest
              </button>
            </div>
          </div>

          {harvests.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '32px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>
                No harvests logged yet
              </div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 16px' }}>
                When you take an animal in the field, log it here — species, weapon, and (optionally) the spot. Your county's quota and any CWD sampling rules are checked automatically.
              </p>
              <button onClick={() => router.visit(new_url)} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#fff', background: BLAZE, border: 'none', padding: '11px 20px', cursor: 'pointer' }}>
                Log Your First Harvest
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
              {harvests.map(h => (
                <div key={h.id} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '16px 20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }}>
                  <div>
                    <div style={{ fontFamily: DISPLAY, fontSize: '17px', color: INK, lineHeight: 1.2 }}>
                      {h.species}
                      {h.antler_score != null && (
                        <span style={{ fontFamily: MONO, fontSize: '11px', color: BLAZE, marginLeft: '8px' }}>{h.antler_score}&quot;</span>
                      )}
                    </div>
                    <div style={{ fontSize: '13px', color: '#6b5e50', marginTop: '3px' }}>
                      {h.property_title} · {h.weapon}
                    </div>
                    {h.photo_urls.length > 0 && (
                      <div style={{ display: 'flex', gap: '6px', marginTop: '8px' }}>
                        {h.photo_urls.slice(0, 4).map(url => (
                          <img key={url} src={url} alt="" style={{ width: '44px', height: '44px', objectFit: 'cover', borderRadius: '3px', border: '1px solid #e5e0d8', display: 'block' }} />
                        ))}
                        {h.photo_urls.length > 4 && (
                          <div style={{ width: '44px', height: '44px', borderRadius: '3px', border: '1px solid #e5e0d8', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: MONO, fontSize: '11px', color: '#6b5e50' }}>
                            +{h.photo_urls.length - 4}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontFamily: MONO, fontSize: '12px', color: '#6b5e50' }}>{h.harvest_date}</div>
                    {h.is_public && (
                      <div style={{ fontFamily: MONO, fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b7856', marginTop: '4px', fontWeight: 700 }}>
                        Public
                      </div>
                    )}
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
