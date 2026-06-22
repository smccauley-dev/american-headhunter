import { useState, useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';

interface NavLink {
    label: string;
    href: string;
    enabled?: boolean;
}

interface NavData {
    logo_url: string | null;
    topbar: {
        tagline: string;
        phone: string;
        link1: string;
        link2: string;
        link3: string;
        link4: string;
    };
    links: NavLink[];
    cta_label: string;
    cta_href: string;
    signin_label: string;
    signin_href: string;
}

interface SharedProps {
    nav: NavData;
    auth?: { authenticated: boolean };
    [key: string]: unknown;
}

/**
 * Universal public-site navigation bar. CMS-driven via the `nav` Inertia shared
 * prop (admin → Navigation Settings) and auth-aware via the `auth` shared prop.
 * Used by every public marketing page so the nav stays consistent site-wide.
 */
export default function PublicNav() {
    const { nav, auth } = usePage<SharedProps>().props;
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const handler = () => setScrolled(window.scrollY > 10);
        handler();
        window.addEventListener('scroll', handler, { passive: true });
        return () => window.removeEventListener('scroll', handler);
    }, []);

    const path = typeof window !== 'undefined' ? window.location.pathname : '';
    const isActive = (href: string): boolean => {
        const hrefPath = href.split('?')[0];
        return hrefPath !== '/' && path === hrefPath;
    };

    const { topbar } = nav;

    return (
        <nav className={`ah-nav${scrolled ? ' scrolled' : ''}`}>
            <div className="nav-strip">
                <div className="nav-strip-left">
                    <span><span className="strip-dot" />{topbar.tagline}</span>
                    {topbar.phone && <span>{topbar.phone}</span>}
                </div>
                <div className="nav-strip-right">
                    {topbar.link1 && <span>{topbar.link1}</span>}
                    {topbar.link2 && <span>{topbar.link2}</span>}
                    {topbar.link3 && <span>{topbar.link3}</span>}
                    {topbar.link4 && <span>{topbar.link4}</span>}
                </div>
            </div>
            <div className="nav-main">
                <Link href="/" className="logo">
                    {nav.logo_url ? (
                        <img src={nav.logo_url} alt="American Headhunter" className="logo-img" />
                    ) : (
                        <>
                            <div className="logo-mark">
                                <span className="logo-mark-letters">AH</span>
                            </div>
                            <div className="logo-text">
                                <span className="logo-name">American Headhunter</span>
                                <span className="logo-tag">Est. 2025 · Hunting Leases</span>
                            </div>
                        </>
                    )}
                </Link>
                <ul className="nav-links">
                    {nav.links.map((link, i) => (
                        <li key={i}>
                            <a
                                href={link.href}
                                style={isActive(link.href) ? { color: 'var(--blaze)' } : undefined}
                            >
                                {link.label}
                            </a>
                        </li>
                    ))}
                </ul>
                <div className="nav-actions">
                    {auth?.authenticated ? (
                        <Link href="/member" className="nav-link-text">My Leases</Link>
                    ) : (
                        <Link href={nav.signin_href} className="nav-link-text">{nav.signin_label}</Link>
                    )}
                    <Link href={nav.cta_href} className="nav-cta">{nav.cta_label}</Link>
                </div>
            </div>
        </nav>
    );
}
