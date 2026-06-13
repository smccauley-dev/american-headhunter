import { Head, router } from '@inertiajs/react'

interface Property {
  id: string
  title: string
  county: string
  state: string
  acres: string | number
}

interface LeaseItem {
  id: string
  status: 'active' | 'pending_signatures'
  start_date: string
  end_date: string
  total_price: string
  days_until_expiry: number | null
  property: Property | null
}

interface Props {
  name: string
  leases: LeaseItem[]
}

const QUICK_LINKS = [
  {
    label: 'My Leases',
    href: '/member/myleases',
    desc: 'View leases, dates, and signing status',
    icon: (
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
    ),
  },
  {
    label: 'My Profile',
    href: '/member/profile',
    desc: 'Edit your details and public profile',
    icon: (
      <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
    ),
  },
  {
    label: 'Find Property',
    href: '/properties',
    desc: 'Browse hunting land and lease listings',
    icon: (
      <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
    ),
  },
  {
    label: 'Membership',
    href: '/member/membership',
    desc: 'Your plan, benefits, and billing',
    icon: (
      <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />
    ),
  },
  {
    label: 'Settings',
    href: '/member/settings',
    desc: 'Security, notifications, and account',
    icon: (
      <>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.241.437-.613.43-.992a7.723 7.723 0 0 1 0-.255c.007-.378-.138-.75-.43-.991l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
      </>
    ),
  },
]

export default function Dashboard({ name, leases }: Props) {
  function handleSignOut() {
    router.post('/logout')
  }

  const pendingLeases = leases.filter(l => l.status === 'pending_signatures')
  const activeCount   = leases.filter(l => l.status === 'active').length

  return (
    <>
      <Head title="Member Portal" />

      <div style={{ minHeight: '100vh', background: '#F8F4EB' }}>

        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '900px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: 700 }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Member Portal
              </span>
            </div>
            <button
              onClick={handleSignOut}
              style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}
            >
              Sign Out
            </button>
          </div>
        </div>

        <div style={{ maxWidth: '900px', margin: '0 auto', padding: '40px 16px 64px' }}>

          {/* Welcome */}
          <div style={{ marginBottom: '32px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
              Welcome back
            </div>
            <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '30px', fontWeight: 400, color: '#0A1512', margin: 0, lineHeight: 1.1 }}>
              {name}
            </h1>
          </div>

          {/* Action required — pending signatures */}
          {pendingLeases.length > 0 && (
            <a
              href="/member/myleases"
              style={{ display: 'block', textDecoration: 'none', marginBottom: '28px', border: '1px solid rgba(200,76,33,0.4)', background: 'rgba(200,76,33,0.06)', padding: '16px 20px' }}
            >
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '4px' }}>
                Action Required
              </div>
              <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#0A1512' }}>
                {pendingLeases.length} lease{pendingLeases.length !== 1 ? 's' : ''} awaiting your signature — review and sign to activate.
              </div>
            </a>
          )}

          {/* Lease summary strip */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', border: '1px solid #d4c9b0', background: '#FBF7EE', padding: '18px 22px', marginBottom: '32px' }}>
            <div style={{ display: 'flex', gap: '36px' }}>
              <div>
                <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '26px', color: '#0A1512', lineHeight: 1 }}>
                  {activeCount}
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginTop: '5px' }}>
                  Active Leases
                </div>
              </div>
              <div>
                <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '26px', color: '#0A1512', lineHeight: 1 }}>
                  {pendingLeases.length}
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginTop: '5px' }}>
                  Awaiting Signature
                </div>
              </div>
            </div>
            <a
              href="/member/myleases"
              style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#0A1512', textDecoration: 'none', border: '1px solid #d4c9b0', padding: '9px 20px' }}
            >
              View My Leases
            </a>
          </div>

          {/* Quick links */}
          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '14px' }}>
            Quick Links
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: '14px' }}>
            {QUICK_LINKS.map(link => (
              <a
                key={link.href}
                href={link.href}
                style={{ display: 'flex', alignItems: 'flex-start', gap: '14px', textDecoration: 'none', border: '1px solid #d4c9b0', background: '#FBF7EE', padding: '18px 20px' }}
              >
                <svg width="22" height="22" fill="none" stroke="#C84C21" strokeWidth="1.5" viewBox="0 0 24 24" style={{ flexShrink: 0, marginTop: '1px' }}>
                  {link.icon}
                </svg>
                <div>
                  <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', color: '#0A1512', marginBottom: '3px' }}>
                    {link.label}
                  </div>
                  <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#6b5e50', lineHeight: 1.4 }}>
                    {link.desc}
                  </div>
                </div>
              </a>
            ))}
          </div>

        </div>
      </div>
    </>
  )
}
