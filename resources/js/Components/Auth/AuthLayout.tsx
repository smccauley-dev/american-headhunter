import { ReactNode } from 'react';

const TOPO_DARK =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 800' fill='none' stroke='%23b8934a' stroke-width='0.6' opacity='0.22'%3E%3Cpath d='M0 100 Q 200 80, 400 140 T 800 120'/%3E%3Cpath d='M0 160 Q 180 140, 380 200 T 800 180'/%3E%3Cpath d='M0 220 Q 220 200, 420 260 T 800 240'/%3E%3Cpath d='M0 280 Q 200 260, 400 320 T 800 300'/%3E%3Cpath d='M0 340 Q 180 320, 380 380 T 800 360'/%3E%3Cpath d='M0 400 Q 220 380, 420 440 T 800 420'/%3E%3Cpath d='M0 460 Q 200 440, 400 500 T 800 480'/%3E%3Cpath d='M0 520 Q 180 500, 380 560 T 800 540'/%3E%3Cpath d='M0 580 Q 220 560, 420 620 T 800 600'/%3E%3Cpath d='M0 640 Q 200 620, 400 680 T 800 660'/%3E%3Cpath d='M0 700 Q 180 680, 380 740 T 800 720'/%3E%3C/svg%3E";

interface AuthLayoutProps {
    children: ReactNode;
    headline: string;
    subheadline?: string;
    chapter?: string;
}

export default function AuthLayout({ children, headline, subheadline, chapter }: AuthLayoutProps) {
    return (
        <div style={{ display: 'flex', minHeight: '100vh', fontFamily: "'Crimson Pro', Georgia, serif" }}>
            {/* Left panel — brand / dark */}
            <div
                style={{
                    display: 'none',
                    width: '30%',
                    background: '#0a1512',
                    backgroundImage: `url("${TOPO_DARK}")`,
                    backgroundSize: '800px 800px',
                    position: 'relative',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    padding: '48px 56px',
                }}
                className="auth-left-panel"
            >
                {/* Registration marks */}
                <span style={{ position: 'absolute', top: 20, left: 20, width: 20, height: 20, borderTop: '1px solid #a89874', borderLeft: '1px solid #a89874' }} />
                <span style={{ position: 'absolute', top: 20, right: 20, width: 20, height: 20, borderTop: '1px solid #a89874', borderRight: '1px solid #a89874' }} />
                <span style={{ position: 'absolute', bottom: 20, left: 20, width: 20, height: 20, borderBottom: '1px solid #a89874', borderLeft: '1px solid #a89874' }} />
                <span style={{ position: 'absolute', bottom: 20, right: 20, width: 20, height: 20, borderBottom: '1px solid #a89874', borderRight: '1px solid #a89874' }} />

                {/* Logo */}
                <div>
                    <a href="/" style={{ display: 'flex', alignItems: 'center', gap: 14, textDecoration: 'none' }}>
                        <div style={{
                            width: 44, height: 44, border: '1.5px solid #e8dcc4',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            fontFamily: "'Fraunces', Georgia, serif", fontSize: 18, fontWeight: 600,
                            color: '#e8dcc4', letterSpacing: '-0.02em',
                        }}>AH</div>
                        <div>
                            <div style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 14, fontWeight: 500, letterSpacing: '0.18em', color: '#e8dcc4', textTransform: 'uppercase' }}>American</div>
                            <div style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.22em', color: '#b8934a', textTransform: 'uppercase' }}>Headhunter</div>
                        </div>
                    </a>
                </div>

                {/* Headline block */}
                <div>
                    {chapter && (
                        <div style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.2em', color: '#c84c21', textTransform: 'uppercase', marginBottom: 20, display: 'flex', alignItems: 'center', gap: 12 }}>
                            <span style={{ display: 'block', width: 24, height: 1, background: '#c84c21' }} />
                            {chapter}
                        </div>
                    )}
                    <h2 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 'clamp(32px, 3vw, 52px)', fontWeight: 400, color: '#e8dcc4', lineHeight: 1.1, margin: '0 0 16px' }}
                        dangerouslySetInnerHTML={{ __html: headline }}
                    />
                    {subheadline && (
                        <p style={{ fontSize: 16, color: '#a89874', lineHeight: 1.6, margin: 0 }}>{subheadline}</p>
                    )}
                </div>

                {/* Footer tagline */}
                <div style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.18em', color: '#4a5440', textTransform: 'uppercase' }}>
                    Hunt Better · Lease Smarter
                </div>
            </div>

            {/* Right panel — form */}
            <div style={{
                flex: 1,
                background: '#e8dcc4',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'center',
                alignItems: 'center',
                padding: '48px 24px',
                minHeight: '100vh',
            }}>
                {/* Mobile logo */}
                <div className="auth-mobile-logo" style={{ marginBottom: 40, textAlign: 'center' }}>
                    <a href="/" style={{ display: 'inline-flex', alignItems: 'center', gap: 12, textDecoration: 'none', color: '#0a1512' }}>
                        <div style={{
                            width: 40, height: 40, border: '1.5px solid #0a1512',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            fontFamily: "'Fraunces', Georgia, serif", fontSize: 16, fontWeight: 600,
                            letterSpacing: '-0.02em',
                        }}>AH</div>
                        <div>
                            <div style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 13, fontWeight: 500, letterSpacing: '0.18em', textTransform: 'uppercase' }}>American Headhunter</div>
                        </div>
                    </a>
                </div>

                <div style={{ width: '100%', maxWidth: 460 }}>
                    {children}
                </div>
            </div>

            <style>{`
                @import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Crimson+Pro:wght@300;400&family=JetBrains+Mono:wght@400;500;600&display=swap');
                @media (min-width: 768px) {
                    .auth-left-panel { display: flex !important; }
                    .auth-mobile-logo { display: none !important; }
                }
                * { box-sizing: border-box; }
            `}</style>
        </div>
    );
}
