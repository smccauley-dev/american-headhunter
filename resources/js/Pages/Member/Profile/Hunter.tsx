import { Head, router, usePage } from '@inertiajs/react'
import { useState, useRef, useEffect, useCallback } from 'react'
import { US_STATE_CODES, US_STATE_NAMES } from '@/lib/usStates'
import FilePondUploader from '@/Components/FilePondUploader'
import {
  EyeIcon,
  EyeSlashIcon,
  KeyIcon,
  LockOpenIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline'

// ── Option lists ──────────────────────────────────────────────────────────────

const SPECIES = [
  { key: 'whitetail',   label: 'Whitetail' },
  { key: 'mule_deer',   label: 'Mule Deer' },
  { key: 'elk',         label: 'Elk' },
  { key: 'turkey',      label: 'Turkey' },
  { key: 'hog',         label: 'Wild Hog' },
  { key: 'black_bear',  label: 'Black Bear' },
  { key: 'waterfowl',   label: 'Waterfowl' },
  { key: 'dove',        label: 'Dove' },
  { key: 'quail',       label: 'Quail' },
  { key: 'pheasant',    label: 'Pheasant' },
  { key: 'small_game',  label: 'Small Game' },
  { key: 'coyote',      label: 'Predator' },
]

const TERRAIN = [
  { key: 'bottom_land',    label: 'Bottom Land' },
  { key: 'hardwood_ridge', label: 'Hardwood Ridge' },
  { key: 'open_field',     label: 'Open Field' },
  { key: 'brush_country',  label: 'Brush Country' },
  { key: 'wetlands',       label: 'Wetlands' },
  { key: 'river',          label: 'River / Creek' },
  { key: 'agricultural',   label: 'Agricultural' },
  { key: 'mountains',      label: 'Mountains' },
]

const SEASONS = [
  { key: 'archery',          label: 'Archery' },
  { key: 'muzzleloader',     label: 'Muzzleloader' },
  { key: 'rifle',            label: 'Rifle' },
  { key: 'spring_turkey',    label: 'Spring Turkey' },
  { key: 'waterfowl_season', label: 'Waterfowl' },
  { key: 'small_game',       label: 'Small Game' },
]

const STYLES = [
  { key: 'stand_hunting',  label: 'Stand Hunting' },
  { key: 'spot_and_stalk', label: 'Spot & Stalk' },
  { key: 'still_hunting',  label: 'Still Hunting' },
  { key: 'calling',        label: 'Calling' },
  { key: 'run_and_gun',    label: 'Run & Gun' },
]

const GENDERS = [
  { key: 'male',              label: 'Male' },
  { key: 'female',            label: 'Female' },
  { key: 'nonbinary',         label: 'Non-binary' },
  { key: 'prefer_not_to_say', label: 'Prefer not to say' },
]

// ── Military branches & first responder types ─────────────────────────────────

const MILITARY_BRANCHES = [
  { key: 'army',           label: 'Army' },
  { key: 'navy',           label: 'Navy' },
  { key: 'marines',        label: 'Marine Corps' },
  { key: 'air_force',      label: 'Air Force' },
  { key: 'coast_guard',    label: 'Coast Guard' },
  { key: 'space_force',    label: 'Space Force' },
  { key: 'national_guard', label: 'National Guard' },
  { key: 'reserves',       label: 'Reserves' },
]

const FR_TYPES = [
  { key: 'law_enforcement', label: 'Law Enforcement' },
  { key: 'fire',            label: 'Fire Fighter' },
  { key: 'emt',             label: 'EMT / Paramedic' },
  { key: 'search_rescue',   label: 'Search & Rescue' },
  { key: 'corrections',     label: 'Corrections Officer' },
  { key: 'dispatch',        label: 'Dispatcher / 911' },
  { key: 'other',           label: 'Other' },
]

// ── Military branch emblems (inline SVG, 16px, fill="currentColor") ──────────

const BRANCH_EMBLEMS: Record<string, React.ReactNode> = {
  army: (
    // 5-pointed star — the Army Black Star
    <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
      <polygon points="12,2.5 14.2,9.1 21.2,9.1 15.6,13.3 17.7,19.9 12,16 6.3,19.9 8.4,13.3 2.8,9.1 9.8,9.1"/>
    </svg>
  ),
  navy: (
    // Anchor — Navy's primary symbol
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="4.5" r="2"/>
      <line x1="12" y1="6.5" x2="12" y2="20"/>
      <line x1="7" y1="11" x2="17" y2="11"/>
      <path d="M7,20 C9,17.5 11,17.5 12,18.5 C13,17.5 15,17.5 17,20"/>
      <circle cx="7" cy="20" r="1" fill="currentColor"/>
      <circle cx="17" cy="20" r="1" fill="currentColor"/>
    </svg>
  ),
  marines: (
    // Eagle, Globe & Anchor — simplified globe with anchor
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="9" r="5"/>
      <ellipse cx="12" cy="9" rx="5" ry="2.5"/>
      <line x1="12" y1="4" x2="12" y2="14"/>
      <line x1="9" y1="19" x2="15" y2="19"/>
      <line x1="12" y1="14" x2="12" y2="22"/>
      <path d="M9,22 C10.5,20.5 13.5,20.5 15,22"/>
    </svg>
  ),
  air_force: (
    // Star with delta wings — USAF symbol
    <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
      <polygon points="12,3.5 13.6,8.5 19,8.5 14.7,11.6 16.3,16.5 12,13.5 7.7,16.5 9.3,11.6 5,8.5 10.4,8.5"/>
      <path d="M4,14 L9,12" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"/>
      <path d="M20,14 L15,12" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"/>
    </svg>
  ),
  coast_guard: (
    // Shield with diagonal racing stripe
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12,3 L20,6.5 L20,13 C20,17.5 16.4,21 12,22 C7.6,21 4,17.5 4,13 L4,6.5 Z"/>
      <line x1="8" y1="9" x2="16" y2="17" strokeWidth="2.4"/>
    </svg>
  ),
  space_force: (
    // Two crossed orbits with center star — Space Force delta/orbit
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.6">
      <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(-45 12 12)"/>
      <ellipse cx="12" cy="12" rx="9" ry="3.5" transform="rotate(45 12 12)"/>
      <polygon points="12,6 13,10.5 17.5,11.5 13,12.5 12,17 11,12.5 6.5,11.5 11,10.5" fill="currentColor" stroke="none"/>
    </svg>
  ),
  national_guard: (
    // Shield with star inside — National Guard emblem
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12,3 L20,6.5 L20,13 C20,17.5 16.4,21 12,22 C7.6,21 4,17.5 4,13 L4,6.5 Z"/>
      <polygon points="12,8 13,11 16.5,11 13.8,13 14.8,16.2 12,14.4 9.2,16.2 10.2,13 7.5,11 11,11" fill="currentColor" stroke="none"/>
    </svg>
  ),
  reserves: (
    // Outlined star — Reserves share branch star but unfilled
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" strokeWidth="1.6">
      <polygon points="12,2.5 14.2,9.1 21.2,9.1 15.6,13.3 17.7,19.9 12,16 6.3,19.9 8.4,13.3 2.8,9.1 9.8,9.1"/>
    </svg>
  ),
}

// ── Gear categories ───────────────────────────────────────────────────────────

const GEAR_CATEGORIES = [
  { key: 'firearms',    label: 'Firearms' },
  { key: 'archery',     label: 'Archery' },
  { key: 'ammunition',  label: 'Ammunition' },
  { key: 'optics',      label: 'Optics' },
  { key: 'clothing',    label: 'Clothing & Camo' },
  { key: 'boots',       label: 'Boots & Footwear' },
  { key: 'pack',        label: 'Packs & Bags' },
  { key: 'electronics', label: 'Electronics' },
  { key: 'knives',      label: 'Knives & Tools' },
  { key: 'calls',       label: 'Calls & Attractants' },
  { key: 'other',       label: 'Other' },
]

// ── Social platforms ──────────────────────────────────────────────────────────

interface SocialPlatform {
  key: string
  label: string
  placeholder: string
  color: string
  href: (val: string) => string
  icon: React.ReactNode
}

const SOCIAL_PLATFORMS: SocialPlatform[] = [
  {
    key: 'instagram', label: 'Instagram', color: '#E1306C',
    placeholder: 'https://instagram.com/yourhandle',
    href: v => v.startsWith('http') ? v : `https://instagram.com/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.334 3.608 1.308.975.975 1.246 2.242 1.308 3.608.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.062 1.366-.333 2.633-1.308 3.608-.975.975-2.242 1.246-3.608 1.308-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.333-3.608-1.308C2.497 19.483 2.226 18.216 2.164 16.85 2.106 15.584 2.094 15.204 2.094 12s.012-3.584.07-4.85c.062-1.366.333-2.633 1.308-3.608.975-.975 2.242-1.246 3.608-1.308C8.346 2.175 8.726 2.163 12 2.163zm0-2.163C8.741 0 8.333.014 7.053.072 4.418.197 2.197 2.418 2.072 5.053.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.125 2.635 2.346 4.856 4.981 4.98C8.333 21.986 8.741 22 12 22s3.668-.014 4.948-.072c2.635-.124 4.856-2.345 4.98-4.98.058-1.28.072-1.689.072-4.948 0-3.259-.014-3.667-.072-4.947-.124-2.635-2.345-4.856-4.98-4.981C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
      </svg>
    ),
  },
  {
    key: 'facebook', label: 'Facebook', color: '#1877F2',
    placeholder: 'https://facebook.com/yourname',
    href: v => v.startsWith('http') ? v : `https://facebook.com/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.885v2.269h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
      </svg>
    ),
  },
  {
    key: 'x', label: 'X (Twitter)', color: '#000000',
    placeholder: 'https://x.com/yourhandle',
    href: v => v.startsWith('http') ? v : `https://x.com/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.747l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
      </svg>
    ),
  },
  {
    key: 'discord', label: 'Discord', color: '#5865F2',
    placeholder: 'Your Discord username',
    href: v => v.startsWith('http') ? v : `https://discord.com/users/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.04.033.05a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
      </svg>
    ),
  },
  {
    key: 'youtube', label: 'YouTube', color: '#FF0000',
    placeholder: 'https://youtube.com/@channel',
    href: v => v.startsWith('http') ? v : `https://youtube.com/@${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
      </svg>
    ),
  },
  {
    key: 'tiktok', label: 'TikTok', color: '#000000',
    placeholder: 'https://tiktok.com/@yourhandle',
    href: v => v.startsWith('http') ? v : `https://tiktok.com/@${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
      </svg>
    ),
  },
  {
    key: 'linkedin', label: 'LinkedIn', color: '#0A66C2',
    placeholder: 'https://linkedin.com/in/yourname',
    href: v => v.startsWith('http') ? v : `https://linkedin.com/in/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
      </svg>
    ),
  },
  {
    key: 'snapchat', label: 'Snapchat', color: '#FFCD00',
    placeholder: 'Your Snapchat username',
    href: v => v.startsWith('http') ? v : `https://snapchat.com/add/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M12.206.793c.99 0 4.347.276 5.93 3.821.529 1.193.403 3.219.317 4.793l-.004.08c-.011.187-.015.374-.012.561.055.354.46.567.8.567.623 0 1.37-.385 1.97-.842.198-.126.434-.24.662-.218.162.012.492.15.492.503 0 .174-.119.348-.344.507-.234.166-.579.299-.924.394-.351.097-.713.183-1.015.266-.3.077-.567.18-.75.35-.188.173-.267.44-.164.706.067.16.152.316.25.464.395.582.895 1.23 1.384 1.877 1.092 1.47 2.21 2.982 2.22 4.476.006.796-.147 1.576-.603 2.2-.448.617-1.19 1.074-2.254 1.3-.254.059-.604.137-.96.203-.367.063-.759.12-.975.232-.24.127-.345.364-.417.59-.093.283-.166.636-.31.964-.165.378-.556.656-.994.595-.296-.04-.562-.176-.832-.293-.296-.133-.63-.25-.94-.25-.342 0-.637.097-.912.224-.264.12-.493.282-.74.38a1.78 1.78 0 0 1-.66.15c-.44 0-.795-.225-.972-.523-.268-.44-.467-.91-.602-1.381-.123-.422-.22-.872-.415-.98-.178-.102-.494-.174-.818-.222a22.54 22.54 0 0 1-1.196-.283c-1.006-.3-1.68-.79-2.1-1.406-.435-.641-.5-1.356-.491-2.056.017-1.536 1.113-3.01 2.218-4.484.5-.648.977-1.29 1.374-1.87.1-.148.19-.312.26-.476.107-.284.03-.55-.16-.72-.183-.165-.45-.266-.76-.342-.307-.08-.67-.163-.93-.27-.321-.126-.518-.315-.518-.507.001-.353.318-.502.488-.512.23-.018.467.095.66.218.6.455 1.343.841 1.976.841.337 0 .73-.213.786-.558.008-.197-.003-.394-.02-.586l-.005-.101c-.083-1.573-.21-3.6.316-4.79C7.858 1.07 11.218.793 12.206.793z"/>
      </svg>
    ),
  },
  {
    key: 'reddit', label: 'Reddit', color: '#FF4500',
    placeholder: 'https://reddit.com/u/username',
    href: v => v.startsWith('http') ? v : `https://reddit.com/u/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/>
      </svg>
    ),
  },
  {
    key: 'twitch', label: 'Twitch', color: '#9146FF',
    placeholder: 'https://twitch.tv/channel',
    href: v => v.startsWith('http') ? v : `https://twitch.tv/${v}`,
    icon: (
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/>
      </svg>
    ),
  },
]

// ── Member portal nav items ───────────────────────────────────────────────────

const NAV_ITEMS = [
  { label: 'My Profile',    href: '/member/profile',    key: 'profile' },
  { label: 'My Membership', href: '/member/membership', key: 'membership' },
  { label: 'My Leases',     href: '/member/myleases',   key: 'leases' },
  { label: 'Property Search', href: '/properties',      key: 'properties' },
  { label: 'Settings',      href: '/member/settings',   key: 'settings' },
]

// ── Types ─────────────────────────────────────────────────────────────────────

interface GearItem {
  id: string
  category: string
  brand: string
  model: string
  notes: string
}

interface HuntingProfile {
  species: string[]
  terrain: string[]
  style: string | null
  seasons: string[]
  years_hunting: number | null
  preferred_states: string[]
}

interface UserData {
  id: string
  email: string
  phone: string | null
  account_type: string
  trust_score: number
  is_veteran: boolean
  is_first_responder: boolean
  is_profile_public: boolean
  username: string | null
  member_since: string
}

interface ProfileData {
  first_name: string
  last_name: string
  display_name: string
  bio: string
  state_code: string | null
  zip_code: string
  date_of_birth: string | null
  gender: string | null
  avatar_url: string | null
  veteran_branch: string | null
  veteran_is_active: boolean
  veteran_service_start: string | null
  veteran_service_end: string | null
  veteran_last_rank: string | null
  veteran_bio: string | null
  first_responder_type: string | null
  first_responder_is_active: boolean
  first_responder_service_start: string | null
  first_responder_service_end: string | null
  first_responder_last_rank: string | null
  first_responder_bio: string | null
  hunting: HuntingProfile
  social_links: Record<string, string>
  gear: { items: GearItem[] }
  visibility: {
    about: 'public' | 'private'
    contact: 'public' | 'private'
    social: 'public' | 'private'
    gear: 'public' | 'private'
    photos: 'public' | 'private'
  }
}

interface PhotoItem {
  id: string
  url: string
}

interface MfaMethodStatus {
  enabled: boolean
  verified_at: string | null
}

interface MfaStatus {
  totp:  MfaMethodStatus
  sms:   MfaMethodStatus
  email: MfaMethodStatus
}

interface LoginEntry {
  id: string
  ip: string | null
  ua: string | null
  success: boolean
  mfa_used: boolean
  at: string
}

interface ActivityEvent {
  type: 'check_in' | 'harvest'
  occurred_at: string
  date_label: string
  // check_in
  time_label?: string
  checked_out?: string | null
  // harvest
  species?: string
  weapon_type?: string
  weight_lbs?: number | null
  antler_score?: number | null
  notes?: string | null
}

interface LeaseSummary {
  id: string
  status: 'active' | 'pending_signatures'
  needs_my_signature: boolean
  start_date: string | null
  end_date: string | null
  total_price: string
  days_until_expiry: number | null
  property: { id: string; title: string; county: string; state: string; acres: string | number } | null
}

// A property the current user owns or manages — populated for landowner
// accounts only, drives the "My Properties" blade in the left sidebar.
interface PropertySummary {
  id: string
  title: string
  slug: string
  county: string | null
  state_code: string | null
  status: string
  total_acres: number | null
  huntable_acres: number | null
  role: string
  listings_count: number
  active_listings_count: number
  primary_photo_url: string | null
}

interface Membership {
  plan_key: string
  display_name: string
  tagline: string | null
  account_type: string
  accent_color: string | null
  is_free: boolean
  source: 'free' | 'subscription' | 'promotion'
  status: string
  status_label: string
  monthly_price: string | null
  annual_price: string | null
  currency: string
  renews_at: string | null
  trial_ends_at: string | null
  cancelled_at: string | null
}

interface Props {
  user: UserData
  profile: ProfileData
  photos: PhotoItem[]
  activity: { events: ActivityEvent[] }
  security: {
    mfa: MfaStatus
    login_history: LoginEntry[]
    enabled_methods: string[]
    suggested_username: string
  }
  leases: LeaseSummary[]
  membership: Membership
  // Set after returning from Stripe Checkout: 'success' | 'cancel'.
  checkout: string | null
  initial_tab: 'about' | 'leases' | 'membership'
  // Null for account types without a CMS profile template (e.g. landowner);
  // the component falls back to DEFAULT_TEMPLATE.
  template: TemplateConfig | null
  // Landowner accounts only.
  properties?: PropertySummary[]
}

// Mirror of ProfileTemplateService::DEFAULT_TEMPLATE — used when the server
// sends no template (account types that have no public profile, e.g. landowner).
const DEFAULT_TEMPLATE: TemplateConfig = {
  decorations: {
    coffee_stain: { enabled: true, opacity: 0.45 },
    registration_marks: { enabled: true },
    topo_background: { enabled: true },
  },
  modules: {},
  theme: { accent: '#C84C21', paper: '#F8F4EB', ink: '#0A1512' },
}

// Admin-controlled CMS config for this profile type (DB 12 profile_templates).
// `order`/`theme` are honored from Slice 2 on; Slice 1 uses decorations + module enable.
interface TemplateConfig {
  decorations: {
    coffee_stain: { enabled: boolean; opacity: number }
    registration_marks: { enabled: boolean }
    topo_background: { enabled: boolean }
  }
  modules: Record<string, { enabled: boolean; order: number }>
  theme: { accent: string; paper: string; ink: string }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function toggle(arr: string[], val: string): string[] {
  return arr.includes(val) ? arr.filter(v => v !== val) : [...arr, val]
}

function labelFor(opts: { key: string; label: string }[], key: string | null): string {
  return opts.find(o => o.key === key)?.label ?? key ?? '—'
}

// ── Shared styles ─────────────────────────────────────────────────────────────

// Field record card shell — 1px ink border, 8px solid ink drop shadow,
// inner dashed border at 8px inset (see docs/design_system.md "Field Record Cards")
const fieldCard: React.CSSProperties = {
  position: 'relative',
  background: 'var(--ah-paper)',
  border: '1px solid var(--ah-ink)',
  boxShadow: '8px 8px 0 var(--ah-ink)',
}

function DashedInset() {
  return (
    <div style={{ position: 'absolute', inset: 8, border: '1px dashed #a89874', pointerEvents: 'none', zIndex: 3 }} />
  )
}

// Decorative coffee-ring stain — a transparent PNG of a real coffee ring.
// Multiply blend lets the parchment/card show through the lighter areas so it
// reads as a stain in the paper. Position/size/rotation come from `style`.
function CoffeeStain01({ style }: { style?: React.CSSProperties }) {
  return (
    <div
      aria-hidden
      style={{
        position: 'absolute',
        pointerEvents: 'none',
        backgroundImage: 'url(/images/coffee-stain-01.png)',
        backgroundSize: 'contain',
        backgroundRepeat: 'no-repeat',
        backgroundPosition: 'center',
        mixBlendMode: 'multiply',
        ...style,
      }}
    />
  )
}

// Small "!" marker beside the Trust Score label — hover or tap for explanation.
// The popover is position:fixed so it escapes the sidebar card's overflow:hidden.
function TrustScoreInfo() {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; left: number }>({ top: 0, left: 0 })
  const btnRef = useRef<HTMLButtonElement>(null)

  const place = useCallback(() => {
    const r = btnRef.current?.getBoundingClientRect()
    if (r) setPos({ top: r.bottom + 8, left: r.left })
  }, [])

  const show = () => { place(); setOpen(true) }

  return (
    <span style={{ display: 'inline-flex', marginLeft: '6px', verticalAlign: 'middle' }}>
      <button
        ref={btnRef}
        type="button"
        onMouseEnter={show}
        onMouseLeave={() => setOpen(false)}
        onClick={() => (open ? setOpen(false) : show())}
        aria-label="What is the trust score?"
        style={{
          width: '13px',
          height: '13px',
          padding: 0,
          border: '1px solid #a89874',
          borderRadius: '50%',
          background: 'transparent',
          color: '#a89874',
          fontFamily: 'JetBrains Mono, monospace',
          fontSize: '9px',
          fontWeight: 700,
          lineHeight: 1,
          cursor: 'help',
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        !
      </button>
      {open && (
        <div style={{
          position: 'fixed',
          top: pos.top,
          left: pos.left,
          width: '240px',
          maxWidth: 'calc(100vw - 24px)',
          background: 'var(--ah-paper)',
          border: '1px solid var(--ah-ink)',
          boxShadow: '4px 4px 0 var(--ah-ink)',
          padding: '12px 14px',
          zIndex: 50,
          textTransform: 'none',
          letterSpacing: 'normal',
        }}>
          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#4a5440', marginBottom: '6px' }}>
            Trust Score
          </div>
          <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', lineHeight: 1.5, color: 'var(--ah-ink)', fontWeight: 400 }}>
            A 0–100 measure of your standing on American Headhunter. It rises with verified email, phone, and ID, completed leases, and positive reviews — and falls with disputes or early lease terminations. Landowners see it when reviewing applications.
          </div>
        </div>
      )}
    </span>
  )
}

const input: React.CSSProperties = {
  fontFamily: 'Crimson Pro, Georgia, serif',
  fontSize: '15px',
  color: 'var(--ah-ink)',
  background: '#fff',
  border: '1px solid #d4c9b0',
  padding: '7px 10px',
  width: '100%',
  outline: 'none',
  boxSizing: 'border-box',
}

const select: React.CSSProperties = { ...input, cursor: 'pointer' }

// ── Sub-components ────────────────────────────────────────────────────────────

function SideLabel({ children }: { children: string }) {
  return (
    <div style={{
      fontFamily: 'JetBrains Mono, monospace',
      fontSize: '9px',
      fontWeight: 600,
      letterSpacing: '.18em',
      textTransform: 'uppercase' as const,
      color: '#a89874',
      marginBottom: '8px',
      borderBottom: '1px solid #e5ddd0',
      paddingBottom: '4px',
    }}>
      {children}
    </div>
  )
}

function SectionLabel({ children }: { children: string }) {
  return (
    <div style={{
      fontFamily: 'JetBrains Mono, monospace',
      fontSize: '9px',
      fontWeight: 600,
      letterSpacing: '.2em',
      textTransform: 'uppercase' as const,
      color: '#a89874',
      marginBottom: '12px',
      borderBottom: '1px solid #e5ddd0',
      paddingBottom: '6px',
    }}>
      {children}
    </div>
  )
}

function DataRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: '140px 1fr', gap: '8px', padding: '7px 0', borderBottom: '1px dotted #d4c9b0' }}>
      <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 600, letterSpacing: '.08em', textTransform: 'uppercase' as const, color: '#a89874' }}>
        {label}
      </span>
      <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: 'var(--ah-ink)' }}>
        {value || <span style={{ color: '#ccc' }}>—</span>}
      </span>
    </div>
  )
}

function EditLabel({ children }: { children: string }) {
  return (
    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase' as const, color: '#a89874', marginBottom: '5px' }}>
      {children}
    </div>
  )
}

function PillToggle({ options, selected, onChange }: {
  options: { key: string; label: string }[]
  selected: string[]
  onChange: (next: string[]) => void
}) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
      {options.map(opt => {
        const on = selected.includes(opt.key)
        return (
          <button
            key={opt.key}
            type="button"
            onClick={() => onChange(toggle(selected, opt.key))}
            style={{
              fontFamily: 'JetBrains Mono, monospace',
              fontSize: '10px',
              fontWeight: 600,
              letterSpacing: '.06em',
              textTransform: 'uppercase' as const,
              padding: '4px 10px',
              border: `1px solid ${on ? 'var(--ah-accent)' : '#d4c9b0'}`,
              background: on ? 'var(--ah-accent)' : 'transparent',
              color: on ? '#fff' : '#999',
              cursor: 'pointer',
              transition: 'all 150ms',
            }}
          >
            {opt.label}
          </button>
        )
      })}
    </div>
  )
}

// ── Main component ────────────────────────────────────────────────────────────

export default function HunterProfile({ user, profile, photos, activity, security, leases, membership, checkout, initial_tab, template, properties }: Props) {
  // Landowner accounts reuse this profile shell but swap the hunting-specific
  // modules (gear, hunting prefs) for a "My Properties" blade.
  const isLandowner = user.account_type === 'landowner'
  // Template-driven decorations + module enablement (admin CMS, DB 12).
  const tpl = template ?? DEFAULT_TEMPLATE
  const deco = tpl.decorations
  const mods = tpl.modules
  const stainEnabled = deco.coffee_stain.enabled
  const stainOpacity = Number(deco.coffee_stain.opacity)
  const showRegMarks = deco.registration_marks.enabled
  const showTopo     = deco.topo_background.enabled
  // about + security always render; the rest appear only when enabled for this type.
  const moduleEnabled = (key: string): boolean =>
    key === 'about' || key === 'security' || mods[key]?.enabled !== false
  // Theme tokens — exposed as CSS custom properties on the page wrapper so every
  // descendant inline style (var(--ah-…)) recolors without prop drilling.
  const themeVars = {
    '--ah-accent': tpl.theme.accent,
    '--ah-paper': tpl.theme.paper,
    '--ah-ink': tpl.theme.ink,
  } as React.CSSProperties
  // Content tabs in admin-defined order; security is always appended last.
  // Landowners have no gear locker, so that tab is dropped for them.
  const orderedTabs = (['about', 'contact', 'social', 'photos', 'gear', 'activity'] as const)
    .filter(k => moduleEnabled(k))
    .filter(k => !(isLandowner && k === 'gear'))
    .sort((a, b) => (Number(mods[a]?.order) || 0) - (Number(mods[b]?.order) || 0))
  const tabList = [...orderedTabs, 'security'] as
    ('about' | 'contact' | 'social' | 'photos' | 'gear' | 'activity' | 'security')[]
  const [editing, setEditing]               = useState(false)
  const [tab, setTab]                       = useState<'about' | 'contact' | 'social' | 'photos' | 'gear' | 'activity' | 'security' | 'leases' | 'membership'>(initial_tab ?? 'about')
  // 'leases' and 'membership' are full-width panels that replace the profile
  // section UI (tab bar + edit actions) rather than render inside it.
  const isFullPanel = tab === 'leases' || tab === 'membership'
  const [saving, setSaving]                 = useState(false)
  const avatarPondRef                       = useRef<any>(null)

  const [form, setForm] = useState({
    first_name:    profile.first_name,
    last_name:     profile.last_name,
    display_name:  profile.display_name,
    bio:           profile.bio,
    state_code:    profile.state_code ?? '',
    zip_code:      profile.zip_code,
    date_of_birth: profile.date_of_birth ?? '',
    gender:        profile.gender ?? '',
    phone:         user.phone ?? '',
    is_veteran:         user.is_veteran,
    is_first_responder: user.is_first_responder,
    hunting: {
      species:          [...profile.hunting.species],
      terrain:          [...profile.hunting.terrain],
      style:            profile.hunting.style ?? '',
      seasons:          [...profile.hunting.seasons],
      years_hunting:    profile.hunting.years_hunting ?? ('' as number | ''),
      preferred_states: [...profile.hunting.preferred_states],
    },
    veteran_branch:                profile.veteran_branch               ?? '',
    veteran_is_active:             profile.veteran_is_active,
    veteran_service_start:         profile.veteran_service_start        ?? '',
    veteran_service_end:           profile.veteran_service_end          ?? '',
    veteran_last_rank:             profile.veteran_last_rank            ?? '',
    veteran_bio:                   profile.veteran_bio                  ?? '',
    first_responder_type:          profile.first_responder_type         ?? '',
    first_responder_is_active:     profile.first_responder_is_active,
    first_responder_service_start: profile.first_responder_service_start ?? '',
    first_responder_service_end:   profile.first_responder_service_end   ?? '',
    first_responder_last_rank:     profile.first_responder_last_rank     ?? '',
    first_responder_bio:           profile.first_responder_bio           ?? '',
    social_links: { ...(profile.social_links ?? {}) },
    gear: { items: [...(profile.gear?.items ?? [])] },
    visibility: {
      about:   profile.visibility?.about   ?? 'public',
      contact: profile.visibility?.contact ?? 'private',
      social:  profile.visibility?.social  ?? 'private',
      gear:    profile.visibility?.gear    ?? 'public',
      photos:  profile.visibility?.photos  ?? 'public',
    },
  })

  function field<K extends keyof typeof form>(key: K, val: (typeof form)[K]) {
    setForm(f => ({ ...f, [key]: val }))
  }

  function hunting<K extends keyof typeof form.hunting>(key: K, val: (typeof form.hunting)[K]) {
    setForm(f => ({ ...f, hunting: { ...f.hunting, [key]: val } }))
  }

  function social(key: string, val: string) {
    setForm(f => ({ ...f, social_links: { ...f.social_links, [key]: val } }))
  }

  function visibility(tab: 'about' | 'contact' | 'social' | 'gear' | 'photos', val: 'public' | 'private') {
    setForm(f => ({ ...f, visibility: { ...f.visibility, [tab]: val } }))
  }

  function gear(items: GearItem[]) {
    setForm(f => ({ ...f, gear: { items } }))
  }

  function handleSave() {
    setSaving(true)
    router.post('/member/profile', form as Record<string, unknown>, {
      onSuccess: () => { setSaving(false); setEditing(false) },
      onError:   () => setSaving(false),
    })
  }

  function handleCancel() {
    setForm({
      first_name:    profile.first_name,
      last_name:     profile.last_name,
      display_name:  profile.display_name,
      bio:           profile.bio,
      state_code:    profile.state_code ?? '',
      zip_code:      profile.zip_code,
      date_of_birth: profile.date_of_birth ?? '',
      gender:        profile.gender ?? '',
      phone:         user.phone ?? '',
      is_veteran:         user.is_veteran,
      is_first_responder: user.is_first_responder,
      hunting: {
        species:          [...profile.hunting.species],
        terrain:          [...profile.hunting.terrain],
        style:            profile.hunting.style ?? '',
        seasons:          [...profile.hunting.seasons],
        years_hunting:    profile.hunting.years_hunting ?? '',
        preferred_states: [...profile.hunting.preferred_states],
      },
      veteran_branch:                profile.veteran_branch               ?? '',
      veteran_is_active:             profile.veteran_is_active,
      veteran_service_start:         profile.veteran_service_start        ?? '',
      veteran_service_end:           profile.veteran_service_end          ?? '',
      veteran_last_rank:             profile.veteran_last_rank            ?? '',
      veteran_bio:                   profile.veteran_bio                  ?? '',
      first_responder_type:          profile.first_responder_type         ?? '',
      first_responder_is_active:     profile.first_responder_is_active,
      first_responder_service_start: profile.first_responder_service_start ?? '',
      first_responder_service_end:   profile.first_responder_service_end   ?? '',
      first_responder_last_rank:     profile.first_responder_last_rank     ?? '',
      first_responder_bio:           profile.first_responder_bio           ?? '',
      social_links: { ...(profile.social_links ?? {}) },
      gear: { items: [...(profile.gear?.items ?? [])] },
      visibility: {
        about:   profile.visibility?.about   ?? 'public',
        contact: profile.visibility?.contact ?? 'private',
        social:  profile.visibility?.social  ?? 'private',
        gear:    profile.visibility?.gear    ?? 'public',
        photos:  profile.visibility?.photos  ?? 'public',
      },
    })
    setEditing(false)
  }

  const displayName = profile.display_name
    || `${profile.first_name} ${profile.last_name}`.trim()
    || user.email

  const initials = (
    `${profile.first_name?.[0] ?? ''}${profile.last_name?.[0] ?? ''}`
  ).toUpperCase() || '?'

  const trustPct = Math.min(100, Math.max(0, user.trust_score))
  const trustColor = trustPct >= 75 ? '#4a7c59' : trustPct >= 45 ? '#b8934a' : 'var(--ah-accent)'

  return (
    <>
      <Head title="My Profile — American Headhunter" />
      <div className={showTopo ? 'topo-bg' : undefined} style={{ ...themeVars, minHeight: '100vh', backgroundColor: '#EDE5D0' }}>

        {/* ── Topbar ─────────────────────────────────────────────────────── */}
        <div style={{ background: 'var(--ah-ink)', borderBottom: '1px solid #b8934a' }}>
          <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '0 24px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>

            {/* Logo block */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
              <div style={{ position: 'relative', width: '42px', height: '42px', flexShrink: 0, margin: '5px' }}>
                {/* Registration mark corners */}
                {showRegMarks && (
                  <>
                    <div style={{ position: 'absolute', top: -5, left: -5, width: 9, height: 9, borderTop: '1.5px solid #a89874', borderLeft: '1.5px solid #a89874' }} />
                    <div style={{ position: 'absolute', bottom: -5, right: -5, width: 9, height: 9, borderBottom: '1.5px solid #a89874', borderRight: '1.5px solid #a89874' }} />
                  </>
                )}
                <div style={{ width: '42px', height: '42px', border: '1px solid #a89874', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--ah-ink)' }}>
                  <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '15px', fontWeight: 500, color: '#F4ECDC', letterSpacing: '.05em' }}>
                    AH
                  </span>
                </div>
              </div>
              <div>
                <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', letterSpacing: '.01em', lineHeight: 1.1 }}>
                  American Headhunter
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.22em', textTransform: 'uppercase', color: '#6b9e8f', marginTop: '3px' }}>
                  Member Portal
                </div>
              </div>
            </div>

            {/* Right nav */}
            <button
              onClick={() => router.post('/logout')}
              style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', background: 'none', border: 'none', cursor: 'pointer' }}
            >
              Sign Out
            </button>
          </div>
        </div>

        {/* ── Layout ─────────────────────────────────────────────────────── */}
        <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '32px 24px 80px' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: '20px', alignItems: 'stretch' }}>

            {/* ── LEFT SIDEBAR ─────────────────────────────────────────── */}
            <div style={{ ...fieldCard, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
              <DashedInset />

              {/* Avatar — inset so it sits inside the dashed border. In edit mode
                  it becomes a FilePond uploader (parity with the admin avatar
                  FileUpload); otherwise it shows the current photo or initials. */}
              {editing ? (
                <div style={{ margin: '16px 16px 0' }}>
                  <FilePondUploader
                    ref={avatarPondRef}
                    name="avatar"
                    maxFileSize="4MB"
                    acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
                    labelIdle='Drag &amp; Drop your photo or <span class="filepond--label-action">Browse</span>'
                    stylePanelLayout="compact"
                    imagePreviewHeight={180}
                    processUrl="/member/profile/avatar"
                    onprocessfiles={() => { router.reload({ only: ['profile'] }); avatarPondRef.current?.removeFiles() }}
                  />
                </div>
              ) : (
                <div
                  style={{
                    position: 'relative',
                    margin: '16px 16px 0',
                    aspectRatio: '1 / 1',
                    background: 'var(--ah-ink)',
                    overflow: 'hidden',
                  }}
                >
                  {profile.avatar_url ? (
                    <img
                      src={profile.avatar_url}
                      alt={displayName}
                      style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }}
                    />
                  ) : (
                    <div style={{
                      position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
                      fontFamily: 'Fraunces, Georgia, serif', fontSize: '52px', fontWeight: 400, color: '#a89874',
                    }}>
                      {initials}
                    </div>
                  )}
                </div>
              )}

              {/* Name + trust + location */}
              <div style={{ padding: '16px 18px 0' }}>
                {editing ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', marginBottom: '14px' }}>
                    <input value={form.first_name} onChange={e => field('first_name', e.target.value)} placeholder="First name" style={input} />
                    <input value={form.last_name} onChange={e => field('last_name', e.target.value)} placeholder="Last name" style={input} />
                    <input value={form.display_name} onChange={e => field('display_name', e.target.value)} placeholder="Display name (optional)" style={input} />
                  </div>
                ) : (
                  <div style={{ marginBottom: '14px' }}>
                    <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', fontWeight: 500, color: 'var(--ah-ink)', lineHeight: 1.25, marginBottom: '3px' }}>
                      {displayName}
                    </div>
                    {profile.state_code && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#a89874', letterSpacing: '.06em' }}>
                        {profile.state_code}{profile.zip_code ? ` · ${profile.zip_code}` : ''}
                      </div>
                    )}
                  </div>
                )}

                {/* Trust score */}
                <div style={{ marginBottom: '16px' }}>
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '5px' }}>
                    Trust Score
                    <TrustScoreInfo />
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ flex: 1, height: '4px', background: '#e5ddd0', position: 'relative', overflow: 'hidden' }}>
                      <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: `${trustPct}%`, background: trustColor, transition: 'width 600ms ease' }} />
                    </div>
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', fontWeight: 700, color: 'var(--ah-ink)', minWidth: '28px', textAlign: 'right' }}>
                      {trustPct}
                    </span>
                  </div>
                </div>
              </div>

              {/* ── Navigation blades ──────────────────────────────────── */}
              <div style={{ borderTop: '1px solid #e5ddd0', margin: '0 16px 4px' }}>
                {NAV_ITEMS.map(item => {
                  // 'leases', 'membership' and 'profile' switch the right-hand
                  // panel in place (same Inertia page); the rest are real links.
                  const isPanel = item.key === 'leases' || item.key === 'membership' || item.key === 'profile'
                  const active = item.key === 'leases'
                    ? tab === 'leases'
                    : item.key === 'membership'
                      ? tab === 'membership'
                      : item.key === 'profile'
                        ? !isFullPanel
                        : false
                  const itemStyle: React.CSSProperties = {
                    display: 'flex',
                    alignItems: 'center',
                    width: '100%',
                    padding: '11px 8px',
                    fontFamily: 'JetBrains Mono, monospace',
                    fontSize: '10px',
                    fontWeight: 600,
                    letterSpacing: '.1em',
                    textTransform: 'uppercase',
                    textDecoration: 'none',
                    textAlign: 'left',
                    color: active ? 'var(--ah-accent)' : '#6b7856',
                    background: active ? 'rgba(200,76,33,0.05)' : 'transparent',
                    borderLeft: active ? '2px solid var(--ah-accent)' : '2px solid transparent',
                    cursor: 'pointer',
                    transition: 'all 150ms',
                  }
                  return isPanel ? (
                    <button
                      key={item.key}
                      type="button"
                      onClick={() => setTab(item.key === 'leases' ? 'leases' : item.key === 'membership' ? 'membership' : 'about')}
                      style={{ ...itemStyle, borderTop: 'none', borderRight: 'none', borderBottom: 'none', borderRadius: 0, margin: 0, appearance: 'none' }}
                    >
                      {item.label}
                    </button>
                  ) : (
                    <a key={item.key} href={item.href} style={itemStyle}>
                      {item.label}
                    </a>
                  )
                })}
              </div>

              {/* ── My Properties (landowner) ───────────────────────────── */}
              {isLandowner && (
                <div style={{ padding: '0 18px 16px' }}>
                  <SideLabel>My Properties</SideLabel>
                  {(properties && properties.length > 0) ? (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                      {properties.map(p => (
                        <a
                          key={p.id}
                          href={`/member/properties/${p.id}`}
                          style={{ display: 'block', textDecoration: 'none', border: '1px solid #e5ddd0', background: '#F3EDD8', padding: '8px 9px' }}
                        >
                          <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '12px', fontWeight: 500, color: 'var(--ah-ink)', lineHeight: 1.25, marginBottom: '3px' }}>
                            {p.title}
                          </div>
                          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#a89874', letterSpacing: '.04em', marginBottom: '4px' }}>
                            {[p.county, p.state_code].filter(Boolean).join(', ')}
                          </div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '1px 6px', background: p.status === 'active' ? 'var(--ah-accent)' : 'var(--ah-ink)', color: '#fff' }}>
                              {p.status}
                            </span>
                            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#6b7856' }}>
                              {p.active_listings_count}/{p.listings_count} listings
                            </span>
                            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: '#a89874', marginLeft: 'auto' }}>
                              {p.role}
                            </span>
                          </div>
                        </a>
                      ))}
                    </div>
                  ) : (
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#ccc', fontStyle: 'italic' }}>No properties yet</span>
                  )}
                  <a
                    href="/member/properties/create"
                    style={{ display: 'block', marginTop: '10px', textAlign: 'center', fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 0', background: 'var(--ah-ink)', color: '#F4ECDC', textDecoration: 'none' }}
                  >
                    + Add Property
                  </a>
                </div>
              )}

              {/* ── Hunting Areas ───────────────────────────────────────── */}
              {!isLandowner && (
              <div style={{ padding: '0 18px 16px' }}>
                <SideLabel>Hunting Areas</SideLabel>
                {editing ? (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '3px' }}>
                    {US_STATE_CODES.map(st => {
                      const on = form.hunting.preferred_states.includes(st)
                      return (
                        <button
                          key={st}
                          type="button"
                          onClick={() => hunting('preferred_states', toggle(form.hunting.preferred_states, st))}
                          style={{
                            fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600,
                            padding: '2px 5px', border: `1px solid ${on ? 'var(--ah-accent)' : '#d4c9b0'}`,
                            background: on ? 'var(--ah-accent)' : 'transparent', color: on ? '#fff' : '#bbb',
                            cursor: 'pointer',
                          }}
                        >
                          {st}
                        </button>
                      )
                    })}
                  </div>
                ) : profile.hunting.preferred_states.length > 0 ? (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                    {profile.hunting.preferred_states.map(st => (
                      <span key={st} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, padding: '2px 8px', background: 'var(--ah-ink)', color: '#b8934a', letterSpacing: '.06em' }}>
                        {st}
                      </span>
                    ))}
                  </div>
                ) : (
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#ccc', fontStyle: 'italic' }}>Not set</span>
                )}
              </div>
              )}

              {/* ── Game Pursued ────────────────────────────────────────── */}
              {!isLandowner && (
              <div style={{ padding: '14px 2px 20px', borderTop: '1px solid #e5ddd0', margin: '0 16px' }}>
                <SideLabel>Game Pursued</SideLabel>
                {editing ? (
                  <PillToggle options={SPECIES} selected={form.hunting.species} onChange={v => hunting('species', v)} />
                ) : profile.hunting.species.length > 0 ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '3px' }}>
                    {profile.hunting.species.map(k => (
                      <span key={k} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#4a5440', letterSpacing: '.04em' }}>
                        · {labelFor(SPECIES, k)}
                      </span>
                    ))}
                  </div>
                ) : (
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#ccc', fontStyle: 'italic' }}>Not set</span>
                )}
              </div>
              )}

              {/* ── Social links (view mode only — shows only platforms with values) */}
              {(() => {
                const active = SOCIAL_PLATFORMS.filter(p => profile.social_links?.[p.key])
                if (!active.length) return null
                return (
                  <div style={{ padding: '14px 2px 16px', borderTop: '1px solid #e5ddd0', margin: '0 16px' }}>
                    <SideLabel>Social</SideLabel>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                      {active.map(p => (
                        <a
                          key={p.key}
                          href={p.href(profile.social_links[p.key])}
                          target="_blank"
                          rel="noopener noreferrer"
                          title={p.label}
                          style={{ color: p.color, display: 'flex', alignItems: 'center', lineHeight: 1 }}
                        >
                          {p.icon}
                        </a>
                      ))}
                    </div>
                  </div>
                )
              })()}

              {/* Member since */}
              <div style={{ padding: '12px 10px', borderTop: '1px solid #e5ddd0', background: '#F3EDD8', margin: 'auto 16px 16px' }}>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '3px' }}>
                  Member Since
                </div>
                <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b7856', letterSpacing: '.04em' }}>
                  {user.member_since}
                </span>
              </div>
            </div>

            {/* ── RIGHT MAIN ───────────────────────────────────────────── */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '20px', position: 'relative' }}>

              {/* Header card */}
              <div style={{ ...fieldCard, padding: '24px 28px' }}>
                <DashedInset />

                {/* Registration marks — surveyor's corner marks between dashed line and content */}
                {showRegMarks && ([
                  { top: 13, left: 13, borderTop: '1px solid #a89874', borderLeft: '1px solid #a89874' },
                  { bottom: 13, right: 13, borderBottom: '1px solid #a89874', borderRight: '1px solid #a89874' },
                ] as React.CSSProperties[]).map((pos, i) => (
                  <div key={i} style={{ position: 'absolute', width: 10, height: 10, pointerEvents: 'none', zIndex: 4, ...pos }} />
                ))}

                {/* Field record strip — label + ID left, rotated stamp right */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', paddingBottom: '14px', borderBottom: '1px solid #d4c9b0', marginBottom: '18px' }}>
                  <div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.2em', textTransform: 'uppercase', color: '#4a5440', marginBottom: '4px' }}>
                      Field Record
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', fontWeight: 500, color: 'var(--ah-ink)' }}>
                      AH-{user.id.slice(0, 8).toUpperCase()}
                      {profile.state_code && (
                        <span style={{ color: '#a89874', marginLeft: '8px', fontWeight: 400 }}>
                          · {US_STATE_NAMES[profile.state_code] ?? profile.state_code}
                        </span>
                      )}
                    </div>
                  </div>
                  <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '11px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--ah-accent)', border: '1.5px solid var(--ah-accent)', padding: '3px 10px', transform: 'rotate(-6deg)', marginRight: '6px' }}>
                    {isLandowner ? 'Landowner' : 'Hunter'}
                  </div>
                </div>

                <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: '20px' }}>
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap', marginBottom: '4px' }}>
                      <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '26px', fontWeight: 400, color: 'var(--ah-ink)', margin: 0, lineHeight: 1.1 }}>
                        {displayName}
                      </h1>
                      {(editing ? form.is_veteran : user.is_veteran) && (() => {
                        const branch = (editing ? form.veteran_branch : profile.veteran_branch) ?? ''
                        const emblem = branch ? BRANCH_EMBLEMS[branch] : null
                        return (
                          <div style={{ display: 'inline-flex', alignItems: 'center', gap: '5px', color: '#b8934a', border: '1.5px solid #b8934a', padding: '3px 10px', flexShrink: 0, transform: 'rotate(-3deg)' }}>
                            {emblem}
                            <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '11px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase' }}>
                              Veteran
                            </span>
                          </div>
                        )
                      })()}
                      {(editing ? form.is_first_responder : user.is_first_responder) && (
                        <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '11px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase', color: '#4a7c59', border: '1.5px solid #4a7c59', padding: '3px 10px', flexShrink: 0, transform: 'rotate(2deg)', display: 'inline-block' }}>
                          First Responder
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Action buttons — profile editing only, not on full-width panels */}
                <div style={{ display: isFullPanel ? 'none' : 'flex', gap: '8px' }}>
                  {editing ? (
                    <>
                      <button
                        onClick={handleSave}
                        disabled={saving}
                        style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'var(--ah-ink)', color: '#F4ECDC', border: 'none', cursor: saving ? 'not-allowed' : 'pointer', opacity: saving ? 0.7 : 1 }}
                      >
                        {saving ? 'Saving…' : 'Save Changes'}
                      </button>
                      <button
                        onClick={handleCancel}
                        style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'transparent', color: 'var(--ah-ink)', border: '1px solid #d4c9b0', cursor: 'pointer' }}
                      >
                        Cancel
                      </button>
                    </>
                  ) : (
                    <button
                      onClick={() => setEditing(true)}
                      style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'transparent', color: 'var(--ah-ink)', border: '1px solid #d4c9b0', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '7px' }}
                    >
                      <svg width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                      Edit Profile
                    </button>
                  )}
                </div>
              </div>

              {/* Tabs + content */}
              <div style={fieldCard}>
                <DashedInset />
                {/* Tab bar — profile sections only; full-width panels replace it */}
                <div style={{ display: isFullPanel ? 'none' : 'flex', borderBottom: '1px solid #e5ddd0', margin: '14px 16px 0', padding: '0 12px' }}>
                  {tabList.map(t => {
                    const visKey = t as 'about' | 'contact' | 'social' | 'gear' | 'photos'
                    const hasVis = t === 'about' || t === 'contact' || t === 'social' || t === 'gear' || t === 'photos'
                    const vis = hasVis ? form.visibility?.[visKey] : null
                    const TAB_LABELS = { about: 'About', contact: 'Contact', social: 'Social', photos: 'Photos', gear: 'Hunting Gear', activity: 'Activity', security: 'Security' }
                    return (
                      <button
                        key={t}
                        onClick={() => setTab(t)}
                        style={{
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700,
                          letterSpacing: '.12em', textTransform: 'uppercase', padding: '14px 0',
                          marginRight: '24px', background: 'none', border: 'none',
                          borderBottom: tab === t ? '2px solid var(--ah-accent)' : '2px solid transparent',
                          color: tab === t ? 'var(--ah-ink)' : '#6b5e50', cursor: 'pointer',
                          display: 'flex', alignItems: 'center', gap: '5px',
                        }}
                      >
                        {TAB_LABELS[t]}
                        {hasVis && vis === 'private' && (
                          <svg width="9" height="9" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" style={{ opacity: 0.5, marginBottom: '1px' }}>
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
                          </svg>
                        )}
                        {t === 'photos' && photos.length > 0 && (
                          <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, background: tab === t ? 'var(--ah-accent)' : '#e5ddd0', color: tab === t ? '#fff' : '#a89874', borderRadius: '8px', padding: '1px 5px', marginLeft: '1px' }}>
                            {photos.length}
                          </span>
                        )}
                        {t === 'gear' && form.gear?.items?.length > 0 && (
                          <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, background: tab === t ? 'var(--ah-accent)' : '#e5ddd0', color: tab === t ? '#fff' : '#a89874', borderRadius: '8px', padding: '1px 5px', marginLeft: '1px' }}>
                            {form.gear.items.length}
                          </span>
                        )}
                      </button>
                    )
                  })}
                </div>

                {/* Tab content */}
                <div style={{ padding: '18px 28px 28px' }}>
                  {tab === 'about' ? (
                    <AboutTab
                      user={user}
                      profile={profile}
                      form={form}
                      editing={editing}
                      isLandowner={isLandowner}
                      onField={field}
                      onHunting={hunting}
                      visibilityValue={form.visibility?.about ?? 'public'}
                      onVisibility={v => visibility('about', v)}
                    />
                  ) : tab === 'contact' ? (
                    <ContactTab
                      user={user}
                      form={form}
                      editing={editing}
                      onField={field}
                      visibilityValue={form.visibility?.contact ?? 'private'}
                      onVisibility={v => visibility('contact', v)}
                    />
                  ) : tab === 'social' ? (
                    <SocialTab
                      profile={profile}
                      form={form}
                      editing={editing}
                      onSocial={social}
                      visibilityValue={form.visibility?.social ?? 'private'}
                      onVisibility={v => visibility('social', v)}
                    />
                  ) : tab === 'photos' ? (
                    <PhotosTab
                      photos={photos}
                      editing={editing}
                      visibilityValue={form.visibility?.photos ?? 'public'}
                      onVisibility={v => visibility('photos', v)}
                    />
                  ) : tab === 'gear' ? (
                    <GearTab
                      items={form.gear?.items ?? []}
                      editing={editing}
                      onGear={gear}
                      visibilityValue={form.visibility?.gear ?? 'public'}
                      onVisibility={v => visibility('gear', v)}
                    />
                  ) : tab === 'activity' ? (
                    <ActivityTab events={activity.events} />
                  ) : tab === 'leases' ? (
                    <LeasesTab leases={leases} />
                  ) : tab === 'membership' ? (
                    <MembershipTab membership={membership} checkout={checkout} />
                  ) : (
                    <SecurityTab
                      mfa={security.mfa}
                      loginHistory={security.login_history}
                      enabledMethods={security.enabled_methods}
                      isProfilePublic={user.is_profile_public}
                      username={user.username}
                      suggestedUsername={security.suggested_username}
                    />
                  )}
                </div>
              </div>

              {/* Coffee-ring stain — decorative, rendered last so it tints both
                  cards via multiply blend. Straddles the header/section boundary
                  on the right (see design mock). */}
              {stainEnabled && (
                <CoffeeStain01 style={{ top: '56px', right: '-26px', width: '260px', height: '260px', transform: 'rotate(-9deg)', opacity: stainOpacity, zIndex: 6 }} />
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  )
}

// ── My Leases tab ─────────────────────────────────────────────────────────────

function LeasesTab({ leases }: { leases: LeaseSummary[] }) {
  if (leases.length === 0) {
    return (
      <div style={{ textAlign: 'center', padding: '48px 20px' }}>
        <svg width="40" height="40" fill="none" stroke="#c2b48f" strokeWidth="1.25" viewBox="0 0 24 24" style={{ margin: '0 auto 16px' }}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
        </svg>
        <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '18px', color: 'var(--ah-ink)', marginBottom: '6px' }}>
          No leases yet
        </div>
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', maxWidth: '360px', margin: '0 auto 20px', lineHeight: 1.5 }}>
          When you secure a hunting lease, it will appear here with its dates, terms, and signing status.
        </p>
        <a
          href="/properties"
          style={{ display: 'inline-block', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: 'var(--ah-ink)', color: '#F4ECDC', textDecoration: 'none' }}
        >
          Browse Properties
        </a>
      </div>
    )
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
      {leases.map(lease => (
        <ProfileLeaseCard key={lease.id} lease={lease} />
      ))}
    </div>
  )
}

function ProfileLeaseCard({ lease }: { lease: LeaseSummary }) {
  const pending = lease.status === 'pending_signatures'
  const needsMySignature = pending && lease.needs_my_signature
  const awaitingCountersign = pending && !lease.needs_my_signature
  const expiringSoon = lease.days_until_expiry !== null && lease.days_until_expiry <= 30 && lease.days_until_expiry > 0

  return (
    <div style={{ border: '1px solid #d4c9b0', background: '#FBF7EE' }}>
      {/* Dark header strip */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 18px', background: 'var(--ah-ink)' }}>
        <div>
          <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', color: '#F4ECDC', lineHeight: 1.1 }}>
            {lease.property?.title ?? 'Property'}
          </div>
          {lease.property && (
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.08em', color: '#9aa890', marginTop: '4px' }}>
              {[lease.property.county, lease.property.state].filter(Boolean).join(', ')}
              {lease.property.acres ? ` · ${lease.property.acres} ac` : ''}
            </div>
          )}
        </div>
        <span style={{
          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase',
          padding: '4px 10px', borderRadius: '2px',
          background: pending ? 'rgba(184,147,74,0.18)' : 'rgba(74,124,89,0.2)',
          color: pending ? '#d8b15e' : '#7bbd8e',
          border: pending ? '1px solid rgba(184,147,74,0.5)' : '1px solid rgba(74,124,89,0.5)',
        }}>
          {needsMySignature ? 'Signature Required' : awaitingCountersign ? 'Awaiting Landowner' : 'Active'}
        </span>
      </div>

      {/* Detail grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '1px', background: '#e5ddd0' }}>
        {[
          { label: 'Start', value: lease.start_date ?? '—' },
          { label: 'End', value: lease.end_date ?? '—' },
          { label: 'Total', value: `$${lease.total_price}` },
        ].map(cell => (
          <div key={cell.label} style={{ background: '#FBF7EE', padding: '12px 18px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '4px' }}>
              {cell.label}
            </div>
            <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '15px', color: 'var(--ah-ink)' }}>
              {cell.value}
            </div>
          </div>
        ))}
      </div>

      {expiringSoon && (
        <div style={{ padding: '8px 18px', background: 'rgba(200,76,33,0.07)', borderTop: '1px solid #e5ddd0', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', color: 'var(--ah-accent)' }}>
          Expires in {lease.days_until_expiry} day{lease.days_until_expiry === 1 ? '' : 's'}
        </div>
      )}

      {awaitingCountersign && (
        <div style={{ padding: '8px 18px', background: 'rgba(74,124,89,0.08)', borderTop: '1px solid #e5ddd0', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', color: '#4a7c59' }}>
          You've signed — awaiting the landowner's countersignature.
        </div>
      )}

      {/* Actions */}
      <div style={{ display: 'flex', gap: '8px', padding: '14px 18px', borderTop: '1px solid #e5ddd0' }}>
        {needsMySignature && (
          <a
            href={`/member/leases/${lease.id}/sign`}
            style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'var(--ah-accent)', color: '#fff', textDecoration: 'none' }}
          >
            Sign Now
          </a>
        )}
        <a
          href={`/member/leases/${lease.id}`}
          style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'transparent', color: 'var(--ah-ink)', border: '1px solid #d4c9b0', textDecoration: 'none' }}
        >
          View Lease
        </a>
      </div>
    </div>
  )
}

// ── My Membership tab ─────────────────────────────────────────────────────────

function MembershipTab({ membership, checkout }: { membership: Membership; checkout: string | null }) {
  // Status pill palette — mirrors the lease card's status styling vocabulary.
  const palette: Record<string, { bg: string; color: string; border: string }> = {
    active:   { bg: 'rgba(74,124,89,0.2)',  color: '#7bbd8e', border: '1px solid rgba(74,124,89,0.5)' },
    trialing: { bg: 'rgba(184,147,74,0.18)', color: '#d8b15e', border: '1px solid rgba(184,147,74,0.5)' },
    promo:    { bg: 'rgba(200,76,33,0.18)',  color: '#e08a5f', border: '1px solid rgba(200,76,33,0.5)' },
    past_due: { bg: 'rgba(176,58,46,0.2)',   color: '#e0897f', border: '1px solid rgba(176,58,46,0.5)' },
    free:     { bg: 'rgba(168,152,116,0.18)', color: '#c2b48f', border: '1px solid rgba(168,152,116,0.5)' },
  }
  const pill = palette[membership.status] ?? palette.free

  const priceLabel = membership.is_free
    ? 'Free'
    : membership.monthly_price && membership.monthly_price !== '0.00'
      ? `$${membership.monthly_price}/mo`
      : membership.annual_price && membership.annual_price !== '0.00'
        ? `$${membership.annual_price}/yr`
        : 'Free'

  const renewLabel = membership.source === 'promotion' ? 'Promo Ends' : 'Renews'

  const cells = [
    { label: 'Price', value: priceLabel },
    { label: 'Billing', value: membership.status_label },
    { label: renewLabel, value: membership.renews_at ?? '—' },
  ]

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
      {checkout === 'success' && (
        <div style={{ padding: '12px 16px', background: 'rgba(74,124,89,0.12)', border: '1px solid rgba(74,124,89,0.4)', fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.04em', color: '#3f6b4d' }}>
          Payment received — your membership is activating. This page will reflect the new plan shortly.
        </div>
      )}
      {checkout === 'cancel' && (
        <div style={{ padding: '12px 16px', background: 'rgba(168,152,116,0.14)', border: '1px solid #d4c9b0', fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.04em', color: '#7a6c4c' }}>
          Checkout canceled — no changes were made to your membership.
        </div>
      )}
      <div style={{ border: '1px solid #d4c9b0', background: '#FBF7EE' }}>
        {/* Dark header strip */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 18px', background: 'var(--ah-ink)' }}>
          <div>
            <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', color: '#F4ECDC', lineHeight: 1.1 }}>
              {membership.display_name}
            </div>
            {membership.tagline && (
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.08em', color: '#9aa890', marginTop: '4px' }}>
                {membership.tagline}
              </div>
            )}
          </div>
          <span style={{
            fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase',
            padding: '4px 10px', borderRadius: '2px',
            background: pill.bg, color: pill.color, border: pill.border,
          }}>
            {membership.status_label}
          </span>
        </div>

        {/* Detail grid */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '1px', background: '#e5ddd0' }}>
          {cells.map(cell => (
            <div key={cell.label} style={{ background: '#FBF7EE', padding: '12px 18px' }}>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '4px' }}>
                {cell.label}
              </div>
              <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '15px', color: 'var(--ah-ink)' }}>
                {cell.value}
              </div>
            </div>
          ))}
        </div>

        {membership.status === 'trialing' && membership.trial_ends_at && (
          <div style={{ padding: '8px 18px', background: 'rgba(184,147,74,0.1)', borderTop: '1px solid #e5ddd0', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', color: '#9a7b2e' }}>
            Trial ends {membership.trial_ends_at}.
          </div>
        )}

        {membership.status === 'past_due' && (
          <div style={{ padding: '8px 18px', background: 'rgba(176,58,46,0.08)', borderTop: '1px solid #e5ddd0', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', color: '#b03a2e' }}>
            Payment is past due — update your billing to keep your benefits.
          </div>
        )}

        {membership.cancelled_at && (
          <div style={{ padding: '8px 18px', background: 'rgba(200,76,33,0.07)', borderTop: '1px solid #e5ddd0', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.06em', color: 'var(--ah-accent)' }}>
            Cancels {membership.cancelled_at} — access continues until then.
          </div>
        )}

        {/* Actions */}
        <div style={{ display: 'flex', gap: '8px', padding: '14px 18px', borderTop: '1px solid #e5ddd0' }}>
          <a
            href="/pricing"
            style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: membership.is_free ? 'var(--ah-accent)' : 'var(--ah-ink)', color: membership.is_free ? '#fff' : '#F4ECDC', textDecoration: 'none' }}
          >
            {membership.is_free ? 'Upgrade Membership' : 'Change Plan'}
          </a>
          <a
            href="/pricing"
            style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'transparent', color: 'var(--ah-ink)', border: '1px solid #d4c9b0', textDecoration: 'none' }}
          >
            Compare Plans
          </a>
        </div>
      </div>
    </div>
  )
}

// ── Slider toggle (iOS-style, matches Filament Toggle) ────────────────────────

function SliderToggle({ checked, onChange, label }: {
  checked: boolean
  onChange: (val: boolean) => void
  label: string
}) {
  return (
    <label style={{ display: 'inline-flex', alignItems: 'center', gap: '10px', cursor: 'pointer', userSelect: 'none' }}>
      <div
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        style={{
          position: 'relative',
          width: '38px',
          height: '22px',
          background: checked ? '#4a7c59' : '#d4c9b0',
          borderRadius: '11px',
          transition: 'background 200ms',
          flexShrink: 0,
        }}
      >
        <div style={{
          position: 'absolute',
          top: '3px',
          left: checked ? '19px' : '3px',
          width: '16px',
          height: '16px',
          background: '#fff',
          borderRadius: '50%',
          transition: 'left 200ms',
          boxShadow: '0 1px 3px rgba(0,0,0,0.25)',
        }} />
      </div>
      <span style={{
        fontFamily: 'JetBrains Mono, monospace',
        fontSize: '10px',
        fontWeight: 600,
        letterSpacing: '.1em',
        textTransform: 'uppercase' as const,
        color: checked ? '#4a7c59' : '#a89874',
        transition: 'color 200ms',
      }}>
        {label}
      </span>
    </label>
  )
}

// ── Privacy toggle ────────────────────────────────────────────────────────────

function PrivacyToggle({ value, editing, onChange }: {
  value: 'public' | 'private'
  editing: boolean
  onChange: (val: 'public' | 'private') => void
}) {
  const isPublic = value === 'public'
  const lockIcon = (
    <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
    </svg>
  )
  const globeIcon = (
    <svg width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10" /><line x1="2" y1="12" x2="22" y2="12" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
    </svg>
  )

  if (!editing) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: '5px', color: isPublic ? '#4a7c59' : '#a89874' }}>
        {isPublic ? globeIcon : lockIcon}
        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase' as const }}>
          {isPublic ? 'Public' : 'Private'}
        </span>
      </div>
    )
  }

  return (
    <button
      type="button"
      onClick={() => onChange(isPublic ? 'private' : 'public')}
      title={`Currently ${value} — click to toggle`}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: '6px',
        fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600,
        letterSpacing: '.12em', textTransform: 'uppercase' as const,
        padding: '4px 10px', cursor: 'pointer',
        background: isPublic ? 'rgba(74,124,89,0.08)' : 'rgba(168,152,116,0.08)',
        border: `1px solid ${isPublic ? '#4a7c59' : '#d4c9b0'}`,
        color: isPublic ? '#4a7c59' : '#a89874',
      }}
    >
      {isPublic ? globeIcon : lockIcon}
      {isPublic ? 'Public' : 'Private'}
    </button>
  )
}

// ── Privacy header row (used at top of each tab with privacy) ─────────────────

function TabPrivacyHeader({ value, editing, onChange }: {
  value: 'public' | 'private'
  editing: boolean
  onChange: (val: 'public' | 'private') => void
}) {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
      <PrivacyToggle value={value} editing={editing} onChange={onChange} />
    </div>
  )
}

// ── About tab ─────────────────────────────────────────────────────────────────

function AboutTab({ user, profile, form, editing, isLandowner, onField, onHunting, visibilityValue, onVisibility }: {
  user: UserData
  profile: ProfileData
  form: ReturnType<typeof useState<any>>[0]
  editing: boolean
  isLandowner: boolean
  onField: (key: any, val: any) => void
  onHunting: (key: any, val: any) => void
  visibilityValue: 'public' | 'private'
  onVisibility: (val: 'public' | 'private') => void
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '28px' }}>

      <TabPrivacyHeader value={visibilityValue} editing={editing} onChange={onVisibility} />

      {/* Bio */}
      <div>
        <SectionLabel>Bio</SectionLabel>
        {editing ? (
          <textarea
            value={form.bio}
            onChange={e => onField('bio', e.target.value)}
            rows={4}
            maxLength={1000}
            placeholder="Tell other hunters about yourself…"
            style={{ ...input, resize: 'vertical', lineHeight: 1.65 }}
          />
        ) : profile.bio ? (
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', fontWeight: 300, color: '#2a3a34', lineHeight: 1.75, margin: 0 }}>
            {profile.bio}
          </p>
        ) : (
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
            No bio added yet.
          </p>
        )}
      </div>

      {/* Hunting Profile */}
      {!isLandowner && (
      <div>
        <SectionLabel>Hunting Profile</SectionLabel>
        {editing ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
            <div>
              <EditLabel>Game Pursued</EditLabel>
              <PillToggle options={SPECIES} selected={form.hunting.species} onChange={v => onHunting('species', v)} />
            </div>
            <div>
              <EditLabel>Terrain</EditLabel>
              <PillToggle options={TERRAIN} selected={form.hunting.terrain} onChange={v => onHunting('terrain', v)} />
            </div>
            <div>
              <EditLabel>Seasons</EditLabel>
              <PillToggle options={SEASONS} selected={form.hunting.seasons} onChange={v => onHunting('seasons', v)} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <EditLabel>Hunting Style</EditLabel>
                <select value={form.hunting.style} onChange={e => onHunting('style', e.target.value)} style={select}>
                  <option value="">— Select style —</option>
                  {STYLES.map(s => <option key={s.key} value={s.key}>{s.label}</option>)}
                </select>
              </div>
              <div>
                <EditLabel>Years Hunting</EditLabel>
                <input
                  type="number" min={0} max={80}
                  value={form.hunting.years_hunting}
                  onChange={e => onHunting('years_hunting', e.target.value === '' ? '' : Number(e.target.value))}
                  style={input}
                  placeholder="0"
                />
              </div>
            </div>
          </div>
        ) : (
          <div>
            {profile.hunting.style && <DataRow label="Style" value={labelFor(STYLES, profile.hunting.style)} />}
            {profile.hunting.years_hunting != null && <DataRow label="Years Hunting" value={`${profile.hunting.years_hunting} yrs`} />}
            {profile.hunting.seasons.length > 0 && <DataRow label="Seasons" value={profile.hunting.seasons.map(k => labelFor(SEASONS, k)).join(' · ')} />}
            {profile.hunting.terrain.length > 0 && <DataRow label="Terrain" value={profile.hunting.terrain.map(k => labelFor(TERRAIN, k)).join(' · ')} />}
            {profile.hunting.species.length > 0 && <DataRow label="Game" value={profile.hunting.species.map(k => labelFor(SPECIES, k)).join(' · ')} />}
            {!profile.hunting.style && profile.hunting.seasons.length === 0 && (
              <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
                No hunting profile set — click Edit Profile to add details.
              </p>
            )}
          </div>
        )}
      </div>
      )}

      {/* Basic info */}
      <div>
        <SectionLabel>Basic Information</SectionLabel>
        {editing ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <EditLabel>Date of Birth</EditLabel>
                <input type="date" value={form.date_of_birth} onChange={e => onField('date_of_birth', e.target.value)} style={input} />
              </div>
              <div>
                <EditLabel>Gender</EditLabel>
                <select value={form.gender} onChange={e => onField('gender', e.target.value)} style={select}>
                  <option value="">— Prefer not to say —</option>
                  {GENDERS.map(g => <option key={g.key} value={g.key}>{g.label}</option>)}
                </select>
              </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <EditLabel>Home State</EditLabel>
                <select value={form.state_code} onChange={e => onField('state_code', e.target.value)} style={select}>
                  <option value="">— Select state —</option>
                  {US_STATE_CODES.map(st => <option key={st} value={st}>{US_STATE_NAMES[st] ?? st}</option>)}
                </select>
              </div>
              <div>
                <EditLabel>Zip Code</EditLabel>
                <input value={form.zip_code} onChange={e => onField('zip_code', e.target.value)} placeholder="00000" maxLength={10} style={input} />
              </div>
            </div>
          </div>
        ) : (
          <div>
            {profile.date_of_birth && (
              <DataRow
                label="Date of Birth"
                value={new Date(profile.date_of_birth + 'T12:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
              />
            )}
            {profile.gender && <DataRow label="Gender" value={labelFor(GENDERS, profile.gender)} />}
            {profile.state_code && (
              <DataRow
                label="Home State"
                value={`${US_STATE_NAMES[profile.state_code] ?? profile.state_code}${profile.zip_code ? ` · ${profile.zip_code}` : ''}`}
              />
            )}
            <DataRow label="Member Since" value={user.member_since} />
          </div>
        )}
      </div>

      {/* Veteran Service */}
      {(editing || user.is_veteran) && (
        <div>
          <SectionLabel>Veteran Service</SectionLabel>
          {editing ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
              <div>
                <SliderToggle checked={form.is_veteran} onChange={v => onField('is_veteran', v)} label="I am a veteran" />
              </div>
              {form.is_veteran && (
                <>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                    <div>
                      <EditLabel>Branch</EditLabel>
                      <select value={form.veteran_branch} onChange={e => onField('veteran_branch', e.target.value)} style={select}>
                        <option value="">— Select branch —</option>
                        {MILITARY_BRANCHES.map(b => <option key={b.key} value={b.key}>{b.label}</option>)}
                      </select>
                    </div>
                    <div>
                      <EditLabel>Last Rank / Title</EditLabel>
                      <input
                        value={form.veteran_last_rank}
                        onChange={e => onField('veteran_last_rank', e.target.value)}
                        placeholder="e.g. Staff Sergeant, Captain"
                        maxLength={100}
                        style={input}
                      />
                    </div>
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                    <div>
                      <EditLabel>Service Start</EditLabel>
                      <input type="date" value={form.veteran_service_start} onChange={e => onField('veteran_service_start', e.target.value)} style={input} />
                    </div>
                    <div>
                      <EditLabel>Service End</EditLabel>
                      <input type="date" value={form.veteran_service_end} onChange={e => onField('veteran_service_end', e.target.value)} style={input} />
                    </div>
                  </div>
                  <div>
                    <SliderToggle checked={form.veteran_is_active} onChange={v => onField('veteran_is_active', v)} label="Currently on active duty" />
                  </div>
                  <div>
                    <EditLabel>Service Notes (optional)</EditLabel>
                    <textarea
                      value={form.veteran_bio}
                      onChange={e => onField('veteran_bio', e.target.value)}
                      rows={3}
                      maxLength={500}
                      placeholder="MOS, deployments, units served with…"
                      style={{ ...input, resize: 'vertical', lineHeight: 1.65 }}
                    />
                  </div>
                </>
              )}
            </div>
          ) : (
            <div>
              {profile.veteran_branch && <DataRow label="Branch" value={labelFor(MILITARY_BRANCHES, profile.veteran_branch)} />}
              {profile.veteran_last_rank && <DataRow label="Rank" value={profile.veteran_last_rank} />}
              <DataRow label="Status" value={profile.veteran_is_active ? 'Active Duty' : 'Veteran'} />
              {(profile.veteran_service_start || profile.veteran_service_end) && (
                <DataRow
                  label="Service"
                  value={[
                    profile.veteran_service_start ? new Date(profile.veteran_service_start + 'T12:00:00').getFullYear() : null,
                    profile.veteran_service_end   ? new Date(profile.veteran_service_end   + 'T12:00:00').getFullYear() : null,
                  ].filter(Boolean).join(' – ') || null}
                />
              )}
              {profile.veteran_bio && (
                <div style={{ marginTop: '8px' }}>
                  <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontWeight: 300, color: '#2a3a34', lineHeight: 1.75, margin: 0 }}>
                    {profile.veteran_bio}
                  </p>
                </div>
              )}
              {!profile.veteran_branch && !profile.veteran_last_rank && !profile.veteran_bio && (
                <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
                  No service details added yet.
                </p>
              )}
            </div>
          )}
        </div>
      )}

      {/* First Responder */}
      {(editing || user.is_first_responder) && (
        <div>
          <SectionLabel>First Responder</SectionLabel>
          {editing ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
              <div>
                <SliderToggle checked={form.is_first_responder} onChange={v => onField('is_first_responder', v)} label="I am a first responder" />
              </div>
              {form.is_first_responder && (
                <>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                    <div>
                      <EditLabel>Role</EditLabel>
                      <select value={form.first_responder_type} onChange={e => onField('first_responder_type', e.target.value)} style={select}>
                        <option value="">— Select role —</option>
                        {FR_TYPES.map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
                      </select>
                    </div>
                    <div>
                      <EditLabel>Rank / Title</EditLabel>
                      <input
                        value={form.first_responder_last_rank}
                        onChange={e => onField('first_responder_last_rank', e.target.value)}
                        placeholder="e.g. Sergeant, Lieutenant"
                        maxLength={100}
                        style={input}
                      />
                    </div>
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                    <div>
                      <EditLabel>Service Start</EditLabel>
                      <input type="date" value={form.first_responder_service_start} onChange={e => onField('first_responder_service_start', e.target.value)} style={input} />
                    </div>
                    <div>
                      <EditLabel>Service End</EditLabel>
                      <input type="date" value={form.first_responder_service_end} onChange={e => onField('first_responder_service_end', e.target.value)} style={input} />
                    </div>
                  </div>
                  <div>
                    <SliderToggle checked={form.first_responder_is_active} onChange={v => onField('first_responder_is_active', v)} label="Currently active / serving" />
                  </div>
                  <div>
                    <EditLabel>Bio / Notes (optional)</EditLabel>
                    <textarea
                      value={form.first_responder_bio}
                      onChange={e => onField('first_responder_bio', e.target.value)}
                      rows={3}
                      maxLength={500}
                      placeholder="Department, years of service, specializations…"
                      style={{ ...input, resize: 'vertical', lineHeight: 1.65 }}
                    />
                  </div>
                </>
              )}
            </div>
          ) : (
            <div>
              {profile.first_responder_type && <DataRow label="Role" value={labelFor(FR_TYPES, profile.first_responder_type)} />}
              {profile.first_responder_last_rank && <DataRow label="Rank / Title" value={profile.first_responder_last_rank} />}
              <DataRow label="Status" value={profile.first_responder_is_active ? 'Active' : 'Former'} />
              {(profile.first_responder_service_start || profile.first_responder_service_end) && (
                <DataRow
                  label="Service"
                  value={[
                    profile.first_responder_service_start ? new Date(profile.first_responder_service_start + 'T12:00:00').getFullYear() : null,
                    profile.first_responder_service_end   ? new Date(profile.first_responder_service_end   + 'T12:00:00').getFullYear() : null,
                  ].filter(Boolean).join(' – ') || null}
                />
              )}
              {profile.first_responder_bio && (
                <div style={{ marginTop: '8px' }}>
                  <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontWeight: 300, color: '#2a3a34', lineHeight: 1.75, margin: 0 }}>
                    {profile.first_responder_bio}
                  </p>
                </div>
              )}
              {!profile.first_responder_type && !profile.first_responder_last_rank && !profile.first_responder_bio && (
                <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
                  No service details added yet.
                </p>
              )}
            </div>
          )}
        </div>
      )}

    </div>
  )
}

// ── Contact tab ───────────────────────────────────────────────────────────────

function ContactTab({ user, form, editing, onField, visibilityValue, onVisibility }: {
  user: UserData
  form: ReturnType<typeof useState<any>>[0]
  editing: boolean
  onField: (key: any, val: any) => void
  visibilityValue: 'public' | 'private'
  onVisibility: (val: 'public' | 'private') => void
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <TabPrivacyHeader value={visibilityValue} editing={editing} onChange={onVisibility} />
      {editing ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          <div>
            <EditLabel>Phone</EditLabel>
            <input
              value={form.phone}
              onChange={e => onField('phone', e.target.value)}
              placeholder="+1 (555) 000-0000"
              style={input}
            />
          </div>
          <div>
            <EditLabel>Email</EditLabel>
            <div style={{ ...input, color: '#a89874', background: '#f8f6f1', cursor: 'not-allowed' }}>
              {user.email}
            </div>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#bbb', marginTop: '4px', letterSpacing: '.06em' }}>
              Email is managed in account settings.
            </div>
          </div>
        </div>
      ) : (
        <div>
          {user.phone && <DataRow label="Phone" value={user.phone} />}
          <DataRow label="Email" value={user.email} />
        </div>
      )}
    </div>
  )
}

// ── Social tab ────────────────────────────────────────────────────────────────

function SocialTab({ profile, form, editing, onSocial, visibilityValue, onVisibility }: {
  profile: ProfileData
  form: ReturnType<typeof useState<any>>[0]
  editing: boolean
  onSocial: (key: string, val: string) => void
  visibilityValue: 'public' | 'private'
  onVisibility: (val: 'public' | 'private') => void
}) {
  const active = SOCIAL_PLATFORMS.filter(p => profile.social_links?.[p.key])

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
      <TabPrivacyHeader value={visibilityValue} editing={editing} onChange={onVisibility} />
      {editing ? (
        <>
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#6b7856', margin: 0 }}>
            Enter a full URL or just your handle — we'll build the link automatically.
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
            {SOCIAL_PLATFORMS.map(p => (
              <div key={p.key} style={{ display: 'grid', gridTemplateColumns: '130px 1fr', gap: '12px', alignItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <span style={{ color: p.color, display: 'flex', alignItems: 'center', flexShrink: 0 }}>{p.icon}</span>
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase' as const, color: '#a89874' }}>
                    {p.label}
                  </span>
                </div>
                <input
                  value={form.social_links?.[p.key] ?? ''}
                  onChange={e => onSocial(p.key, e.target.value)}
                  placeholder={p.placeholder}
                  style={input}
                />
              </div>
            ))}
          </div>
        </>
      ) : active.length > 0 ? (
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          {active.map(p => (
            <div key={p.key} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 0', borderBottom: '1px solid #f5f0e8' }}>
              <span style={{ color: p.color, display: 'flex', alignItems: 'center', flexShrink: 0 }}>{p.icon}</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase' as const, color: '#a89874', minWidth: '80px', flexShrink: 0 }}>
                {p.label}
              </span>
              <a
                href={p.href(profile.social_links[p.key])}
                target="_blank"
                rel="noopener noreferrer"
                style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#4a7c59', textDecoration: 'none', wordBreak: 'break-all' }}
              >
                {profile.social_links[p.key]}
              </a>
            </div>
          ))}
        </div>
      ) : (
        <div style={{ textAlign: 'center', padding: '48px 0' }}>
          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#ccc', marginBottom: '8px' }}>
            No Social Profiles
          </div>
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
            Click Edit Profile to add your social media links.
          </p>
        </div>
      )}
    </div>
  )
}

// ── Photos tab ────────────────────────────────────────────────────────────────

function PhotosTab({ photos, editing, visibilityValue, onVisibility }: {
  photos: PhotoItem[]
  editing: boolean
  visibilityValue: 'public' | 'private'
  onVisibility: (val: 'public' | 'private') => void
}) {
  const pondRef = useRef<any>(null)
  const [deleting, setDeleting]   = useState<string | null>(null)

  function handleDelete(id: string) {
    setDeleting(id)
    router.delete(`/member/profile/photos/${id}`, {
      preserveState: true,
      onFinish: () => setDeleting(null),
    })
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>

      <TabPrivacyHeader value={visibilityValue} editing={editing} onChange={onVisibility} />

      {/* Upload — FilePond instant-upload (parity with the admin/property uploaders) */}
      <div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '10px' }}>
          <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874' }}>
            {photos.length} {photos.length === 1 ? 'Photo' : 'Photos'}
          </span>
        </div>
        <FilePondUploader
          ref={pondRef}
          allowMultiple
          maxFiles={10}
          maxFileSize="8MB"
          acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
          name="photo"
          labelIdle='Drag &amp; Drop your photos or <span class="filepond--label-action">Browse</span>'
          processUrl="/member/profile/photos"
          onprocessfiles={() => { router.reload({ only: ['photos'] }); pondRef.current?.removeFiles() }}
        />
      </div>

      {/* Grid */}
      {photos.length > 0 ? (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '6px' }}>
          {photos.map(p => (
            <div
              key={p.id}
              style={{ position: 'relative', paddingBottom: '100%', background: 'var(--ah-ink)', overflow: 'hidden' }}
            >
              <img
                src={p.url}
                alt=""
                style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }}
              />
              {editing && (
                <button
                  onClick={() => handleDelete(p.id)}
                  disabled={deleting === p.id}
                  title="Remove photo"
                  style={{
                    position: 'absolute', top: '6px', right: '6px',
                    width: '22px', height: '22px',
                    background: deleting === p.id ? 'rgba(0,0,0,0.4)' : 'rgba(10,21,18,0.75)',
                    border: '1px solid rgba(255,255,255,0.3)',
                    color: '#fff', cursor: deleting === p.id ? 'not-allowed' : 'pointer',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    fontSize: '14px', lineHeight: 1,
                  }}
                >
                  {deleting === p.id ? (
                    <svg width="10" height="10" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                      <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
                    </svg>
                  ) : '×'}
                </button>
              )}
            </div>
          ))}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px 0', border: '1px dashed #d4c9b0', gap: '12px' }}>
          <svg width="32" height="32" fill="none" stroke="#d4c9b0" strokeWidth="1.5" viewBox="0 0 24 24">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
            <circle cx="12" cy="13" r="4" />
          </svg>
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
            No photos yet — click Upload Photos to add some.
          </p>
        </div>
      )}

      {editing && photos.length > 0 && (
        <p style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#bbb', letterSpacing: '.08em', margin: 0 }}>
          × buttons appear in edit mode. Changes save immediately.
        </p>
      )}
    </div>
  )
}

// ── Gear tab ──────────────────────────────────────────────────────────────────

const BLANK_ITEM = { category: 'firearms', brand: '', model: '', notes: '' }

function GearTab({ items, editing, onGear, visibilityValue, onVisibility }: {
  items: GearItem[]
  editing: boolean
  onGear: (items: GearItem[]) => void
  visibilityValue: 'public' | 'private'
  onVisibility: (val: 'public' | 'private') => void
}) {
  const [adding, setAdding]   = useState(false)
  const [newItem, setNewItem] = useState({ ...BLANK_ITEM })
  const [err, setErr]         = useState('')

  function handleAdd() {
    if (!newItem.model.trim()) { setErr('Model / Name is required.'); return }
    onGear([...items, { ...newItem, id: crypto.randomUUID(), brand: newItem.brand.trim(), model: newItem.model.trim(), notes: newItem.notes.trim() }])
    setNewItem({ ...BLANK_ITEM })
    setAdding(false)
    setErr('')
  }

  function handleRemove(id: string) {
    onGear(items.filter(i => i.id !== id))
  }

  function catLabel(key: string) {
    return GEAR_CATEGORIES.find(c => c.key === key)?.label ?? key
  }

  // Group for view mode
  const grouped = GEAR_CATEGORIES
    .map(cat => ({ cat, catItems: items.filter(i => i.category === cat.key) }))
    .filter(g => g.catItems.length > 0)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>

      <TabPrivacyHeader value={visibilityValue} editing={editing} onChange={onVisibility} />

      {/* ── Edit mode: add button + list with delete ── */}
      {editing && (
        <>
          <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
            {!adding && (
              <button
                type="button"
                onClick={() => { setAdding(true); setErr('') }}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '7px', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 18px', background: 'var(--ah-ink)', color: '#F4ECDC', border: 'none', cursor: 'pointer' }}
              >
                <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" /></svg>
                Add Gear
              </button>
            )}
          </div>

          {adding && (
            <div style={{ border: '1px solid #d4c9b0', padding: '18px', background: '#fdfaf4' }}>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.18em', textTransform: 'uppercase', color: '#a89874', marginBottom: '14px', borderBottom: '1px solid #e5ddd0', paddingBottom: '6px' }}>
                New Gear Item
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', marginBottom: '10px' }}>
                <div>
                  <EditLabel>Category</EditLabel>
                  <select
                    value={newItem.category}
                    onChange={e => setNewItem(n => ({ ...n, category: e.target.value }))}
                    style={select}
                  >
                    {GEAR_CATEGORIES.map(c => <option key={c.key} value={c.key}>{c.label}</option>)}
                  </select>
                </div>
                <div>
                  <EditLabel>Brand</EditLabel>
                  <input
                    value={newItem.brand}
                    onChange={e => setNewItem(n => ({ ...n, brand: e.target.value }))}
                    placeholder="e.g. Remington, Sitka, Vortex"
                    style={input}
                  />
                </div>
              </div>
              <div style={{ marginBottom: '10px' }}>
                <EditLabel>Model / Name *</EditLabel>
                <input
                  value={newItem.model}
                  onChange={e => { setNewItem(n => ({ ...n, model: e.target.value })); setErr('') }}
                  placeholder="e.g. Model 700, Kelvin Down Hoody, Razor HD"
                  style={{ ...input, borderColor: err ? 'var(--ah-accent)' : '#d4c9b0' }}
                />
                {err && <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: 'var(--ah-accent)', marginTop: '4px' }}>{err}</div>}
              </div>
              <div style={{ marginBottom: '14px' }}>
                <EditLabel>Notes (optional)</EditLabel>
                <input
                  value={newItem.notes}
                  onChange={e => setNewItem(n => ({ ...n, notes: e.target.value }))}
                  placeholder="e.g. 30-06 Springfield, size 10, 10x42"
                  style={input}
                />
              </div>
              <div style={{ display: 'flex', gap: '8px' }}>
                <button
                  type="button"
                  onClick={handleAdd}
                  style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 18px', background: 'var(--ah-ink)', color: '#F4ECDC', border: 'none', cursor: 'pointer' }}
                >
                  Add to List
                </button>
                <button
                  type="button"
                  onClick={() => { setAdding(false); setErr(''); setNewItem({ ...BLANK_ITEM }) }}
                  style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 18px', background: 'transparent', color: 'var(--ah-ink)', border: '1px solid #d4c9b0', cursor: 'pointer' }}
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {items.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
              {items.map(item => (
                <div key={item.id} style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '9px 12px', border: '1px solid #e5ddd0', background: '#fdfaf4' }}>
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#b8934a', minWidth: '110px', flexShrink: 0 }}>
                    {catLabel(item.category)}
                  </span>
                  <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: 'var(--ah-ink)', flex: 1 }}>
                    {item.brand ? `${item.brand} ` : ''}<strong>{item.model}</strong>
                    {item.notes && <span style={{ color: '#a89874', fontSize: '13px' }}> — {item.notes}</span>}
                  </span>
                  <button
                    type="button"
                    onClick={() => handleRemove(item.id)}
                    title="Remove"
                    style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '14px', lineHeight: 1, background: 'none', border: 'none', color: '#bbb', cursor: 'pointer', padding: '2px 4px', flexShrink: 0 }}
                  >
                    ×
                  </button>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {/* ── View mode: grouped by category ── */}
      {!editing && (
        grouped.length > 0 ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            {grouped.map(({ cat, catItems }) => (
              <div key={cat.key}>
                <SectionLabel>{cat.label}</SectionLabel>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                  {catItems.map(item => (
                    <div key={item.id} style={{ padding: '8px 0', borderBottom: '1px solid #f5f0e8', display: 'flex', flexDirection: 'column', gap: '2px' }}>
                      <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: 'var(--ah-ink)' }}>
                        {item.brand ? `${item.brand} ` : ''}<strong>{item.model}</strong>
                      </span>
                      {item.notes && (
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#a89874', letterSpacing: '.04em' }}>
                          {item.notes}
                        </span>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div style={{ textAlign: 'center', padding: '48px 0' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#ccc', marginBottom: '8px' }}>
              No Gear Listed
            </div>
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
              Click Edit Profile to add your gear.
            </p>
          </div>
        )
      )}
    </div>
  )
}

// ── Security tab ──────────────────────────────────────────────────────────────

function SecurityTab({ mfa, loginHistory, enabledMethods, isProfilePublic, username, suggestedUsername }: {
  mfa: MfaStatus
  loginHistory: LoginEntry[]
  enabledMethods: string[]
  isProfilePublic: boolean
  username: string | null
  suggestedUsername: string
}) {
  const page = usePage<{ flash: { success: string | null; error: string | null }; errors: Record<string, string> }>()
  const flash  = page.props.flash
  const errors = page.props.errors ?? {}

  // ── Change Password form ──────────────────────────────────────────────────
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [pwSaving, setPwSaving] = useState(false)

  function pwField(key: keyof typeof pw, val: string) {
    setPw(p => ({ ...p, [key]: val }))
  }

  function submitPassword() {
    if (!pw.current_password || !pw.password || !pw.password_confirmation) return
    setPwSaving(true)
    router.post('/member/security/password', pw, {
      preserveState: true,
      onFinish: () => {
        setPwSaving(false)
        setPw({ current_password: '', password: '', password_confirmation: '' })
      },
    })
  }

  // ── Public profile ────────────────────────────────────────────────────────
  const [confirmingPublic, setConfirmingPublic] = useState(false)
  const [visibilitySaving, setVisibilitySaving] = useState(false)
  const [usernameInput, setUsernameInput]       = useState(suggestedUsername)

  const USERNAME_RE = /^[a-z][a-z0-9_]{2,29}$/
  const usernameValid = USERNAME_RE.test(usernameInput)

  const [usernameAvailable, setUsernameAvailable] = useState<boolean | null>(null)
  const [usernameChecking, setUsernameChecking]   = useState(false)

  const checkAvailability = useCallback((val: string) => {
    if (!USERNAME_RE.test(val)) { setUsernameAvailable(null); return }
    setUsernameChecking(true)
    fetch(`/member/security/username-check/${encodeURIComponent(val)}`)
      .then(r => r.json())
      .then(d => setUsernameAvailable(d.available ?? false))
      .catch(() => setUsernameAvailable(null))
      .finally(() => setUsernameChecking(false))
  }, [])

  useEffect(() => {
    setUsernameAvailable(null)
    if (!usernameValid) return
    const t = setTimeout(() => checkAvailability(usernameInput), 400)
    return () => clearTimeout(t)
  }, [usernameInput, usernameValid, checkAvailability])

  function submitVisibility(makePublic: boolean) {
    setVisibilitySaving(true)
    const payload: Record<string, unknown> = { is_profile_public: makePublic }
    if (makePublic && !username) payload.username = usernameInput
    router.post('/member/security/profile-visibility', payload, {
      preserveState: true,
      onSuccess: () => setConfirmingPublic(false),
      onFinish:  () => setVisibilitySaving(false),
    })
  }

  // ── MFA disable confirm flow ──────────────────────────────────────────────
  const [disabling, setDisabling]     = useState<string | null>(null)
  const [disablePass, setDisablePass] = useState('')
  const [disableSaving, setDisableSaving] = useState(false)

  function submitDisable(method: string) {
    if (!disablePass) return
    setDisableSaving(true)
    router.post(`/member/security/mfa/${method}/disable`, { current_password: disablePass }, {
      preserveState: true,
      onFinish: () => {
        setDisableSaving(false)
        setDisabling(null)
        setDisablePass('')
      },
    })
  }

  function submitEnable(method: string) {
    router.post(`/member/security/mfa/${method}/enable`, {}, { preserveState: true })
  }

  // ── TOTP enrollment flow (secret + QR + confirm) ──────────────────────────
  const [totpEnroll, setTotpEnroll] = useState<{ secret: string; qr: string } | null>(null)
  const [totpStarting, setTotpStarting] = useState(false)
  const [totpCode, setTotpCode] = useState('')
  const [totpConfirming, setTotpConfirming] = useState(false)
  const [totpError, setTotpError] = useState<string | null>(null)
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null)

  function csrfHeader(): Record<string, string> {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {}
  }

  async function startTotpEnroll() {
    setTotpStarting(true)
    setTotpError(null)
    try {
      const res = await fetch('/member/security/mfa/totp/enroll', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
      })
      const body = await res.json()
      if (!res.ok) { setTotpError(body.message ?? 'Could not start setup.'); return }
      setTotpEnroll({ secret: body.secret, qr: body.qr_code_uri })
      setTotpCode('')
    } catch {
      setTotpError('Could not start setup. Please try again.')
    } finally {
      setTotpStarting(false)
    }
  }

  async function confirmTotpEnroll() {
    if (!totpCode) return
    setTotpConfirming(true)
    setTotpError(null)
    try {
      const res = await fetch('/member/security/mfa/totp/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify({ code: totpCode }),
      })
      const body = await res.json()
      if (!res.ok) { setTotpError(body.message ?? 'That code is incorrect.'); return }
      setTotpEnroll(null)
      setTotpCode('')
      if (body.recovery_codes?.length) {
        setRecoveryCodes(body.recovery_codes)
      } else {
        router.reload({ only: ['security'] })
      }
    } catch {
      setTotpError('Verification failed. Please try again.')
    } finally {
      setTotpConfirming(false)
    }
  }

  function dismissRecoveryCodes() {
    setRecoveryCodes(null)
    router.reload({ only: ['security'] })
  }

  const cardStyle: React.CSSProperties = {
    background: 'var(--ah-paper)',
    border: '1px solid #e5ddd0',
    padding: '20px 22px',
    display: 'flex',
    flexDirection: 'column',
    gap: '14px',
  }

  const sectionHead: React.CSSProperties = {
    fontFamily: 'JetBrains Mono, monospace',
    fontSize: '9px',
    fontWeight: 700,
    letterSpacing: '.2em',
    textTransform: 'uppercase',
    color: '#a89874',
    borderBottom: '1px solid #e5ddd0',
    paddingBottom: '8px',
    marginBottom: '4px',
  }

  const MFA_METHODS: { key: keyof MfaStatus; label: string; description: string }[] = ([
    { key: 'email',  label: 'Email',              description: 'One-time code sent to your email address' },
    { key: 'totp',   label: 'Authenticator App',  description: 'Google Authenticator, Authy, or compatible app' },
    { key: 'sms',    label: 'SMS / Text Message', description: 'One-time code sent to your phone number' },
  ] as const).filter(m => enabledMethods.includes(m.key))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>

      {/* Flash banner */}
      {flash?.success && (
        <div style={{
          background: '#e8f3ec', border: '1px solid #4a7c59', padding: '10px 16px',
          fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#2d5a3d', letterSpacing: '.04em',
        }}>
          {flash.success}
        </div>
      )}
      {flash?.error && (
        <div style={{
          background: '#fdf0ed', border: '1px solid var(--ah-accent)', padding: '10px 16px',
          fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: 'var(--ah-accent)', letterSpacing: '.04em',
        }}>
          {flash.error}
        </div>
      )}

      {/* ── Change Password ───────────────────────────────────────────────── */}
      <div style={cardStyle}>
        <div style={sectionHead}>Change Password</div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', maxWidth: '400px' }}>
          <div>
            <EditLabel>Current Password</EditLabel>
            <input
              type="password"
              value={pw.current_password}
              onChange={e => pwField('current_password', e.target.value)}
              autoComplete="current-password"
              style={input}
            />
            {errors.current_password && (
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)', marginTop: '4px' }}>
                {errors.current_password}
              </div>
            )}
          </div>

          <div>
            <EditLabel>New Password</EditLabel>
            <input
              type="password"
              value={pw.password}
              onChange={e => pwField('password', e.target.value)}
              autoComplete="new-password"
              style={input}
            />
            {errors.password && (
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)', marginTop: '4px' }}>
                {errors.password}
              </div>
            )}
          </div>

          <div>
            <EditLabel>Confirm New Password</EditLabel>
            <input
              type="password"
              value={pw.password_confirmation}
              onChange={e => pwField('password_confirmation', e.target.value)}
              autoComplete="new-password"
              style={input}
            />
          </div>

          <div style={{ paddingTop: '4px' }}>
            <button
              onClick={submitPassword}
              disabled={pwSaving || !pw.current_password || !pw.password || !pw.password_confirmation}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: '7px',
                fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700,
                letterSpacing: '.1em', textTransform: 'uppercase',
                padding: '9px 22px',
                background: pwSaving ? '#d4c9b0' : 'var(--ah-ink)',
                color: pwSaving ? '#a89874' : '#F4ECDC',
                border: 'none', cursor: pwSaving ? 'not-allowed' : 'pointer',
              }}
            >
              <KeyIcon style={{ width: '14px', height: '14px', flexShrink: 0 }} />
              {pwSaving ? 'Saving…' : 'Save Password'}
            </button>
          </div>
        </div>
      </div>

      {/* ── Public Profile ───────────────────────────────────────────────── */}
      <div style={cardStyle}>
        <div style={sectionHead}>Public Profile</div>

        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '20px' }}>
          <div style={{ flex: 1 }}>
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#555', margin: '0 0 6px' }}>
              When enabled, your profile — name, bio, hunting preferences, and gear list — is visible to anyone with the link, including unauthenticated visitors.
            </p>
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#888', margin: 0, fontStyle: 'italic' }}>
              Contact info and social links follow their own visibility settings regardless of this toggle.
            </p>
          </div>
          <div style={{ flexShrink: 0, display: 'flex', alignItems: 'center', gap: '10px' }}>
            {isProfilePublic ? (
              <span style={{
                display: 'inline-flex', alignItems: 'center', gap: '5px',
                fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                letterSpacing: '.12em', textTransform: 'uppercase',
                color: '#4a7c59', background: '#e8f3ec', padding: '3px 8px', border: '1px solid #c3deca',
              }}>
                <EyeIcon style={{ width: '11px', height: '11px' }} /> Public
              </span>
            ) : (
              <span style={{
                display: 'inline-flex', alignItems: 'center', gap: '5px',
                fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                letterSpacing: '.12em', textTransform: 'uppercase',
                color: '#a89874', background: 'var(--ah-paper)', padding: '3px 8px', border: '1px solid #d4c9b0',
              }}>
                <EyeSlashIcon style={{ width: '11px', height: '11px' }} /> Private
              </span>
            )}
            {isProfilePublic ? (
              <button
                onClick={() => submitVisibility(false)}
                disabled={visibilitySaving}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                  letterSpacing: '.1em', textTransform: 'uppercase',
                  padding: '5px 12px', background: 'transparent',
                  color: 'var(--ah-accent)', border: '1px solid var(--ah-accent)',
                  cursor: visibilitySaving ? 'not-allowed' : 'pointer',
                }}
              >
                <EyeSlashIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                Make Private
              </button>
            ) : (
              <button
                onClick={() => setConfirmingPublic(true)}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                  letterSpacing: '.1em', textTransform: 'uppercase',
                  padding: '5px 12px', background: 'var(--ah-ink)',
                  color: '#F4ECDC', border: 'none', cursor: 'pointer',
                }}
              >
                <EyeIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                Make Public
              </button>
            )}
          </div>
        </div>

        {confirmingPublic && (
          <div style={{
            background: '#fffbf0', border: '1px solid #e8d99a',
            padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: '14px',
          }}>
            <div style={{ display: 'flex', gap: '10px', alignItems: 'flex-start' }}>
              <ShieldExclamationIcon style={{ width: '18px', height: '18px', color: '#b8934a', flexShrink: 0, marginTop: '1px' }} />
              <div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', color: 'var(--ah-ink)', marginBottom: '4px' }}>
                  {username ? 'Make your profile public?' : 'Choose your username first'}
                </div>
                <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#666', margin: 0 }}>
                  {username
                    ? 'Anyone on the internet will be able to find and view your profile. You can make it private again at any time.'
                    : 'Your username is your permanent @mention handle and public profile URL. It cannot be changed once set.'}
                </p>
              </div>
            </div>

            {/* Username picker — only shown if not yet set */}
            {!username && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', maxWidth: '340px' }}>
                <EditLabel>Username</EditLabel>
                <div style={{ position: 'relative' }}>
                  <span style={{
                    position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)',
                    fontFamily: 'JetBrains Mono, monospace', fontSize: '13px', color: '#a89874', pointerEvents: 'none',
                  }}>@</span>
                  <input
                    type="text"
                    value={usernameInput}
                    onChange={e => setUsernameInput(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
                    maxLength={30}
                    autoFocus
                    style={{ ...input, paddingLeft: '24px' }}
                  />
                </div>
                {errors.username && (
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)' }}>
                    {errors.username}
                  </div>
                )}
                {usernameInput && !usernameValid && (
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: 'var(--ah-accent)', letterSpacing: '.04em' }}>
                    3–30 chars, must start with a letter, letters/numbers/underscores only.
                  </div>
                )}
                {usernameValid && (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '3px' }}>
                    {usernameChecking && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#a89874', letterSpacing: '.04em' }}>
                        Checking availability…
                      </div>
                    )}
                    {!usernameChecking && usernameAvailable === true && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#4a7c59', letterSpacing: '.04em' }}>
                        ✓ Available
                      </div>
                    )}
                    {!usernameChecking && usernameAvailable === false && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: 'var(--ah-accent)', letterSpacing: '.04em' }}>
                        ✗ Already taken — try another
                      </div>
                    )}
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#6b7280', letterSpacing: '.04em' }}>
                      Profile URL: /hunters/{usernameInput}
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#6b7280', letterSpacing: '.04em' }}>
                      Tag: @{usernameInput}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* If already has username, just show it */}
            {username && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '3px' }}>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#4a7c59', letterSpacing: '.04em' }}>
                  Profile URL: /hunters/{username}
                </div>
                <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#4a7c59', letterSpacing: '.04em' }}>
                  Tag: @{username}
                </div>
              </div>
            )}

            <div style={{ display: 'flex', gap: '8px' }}>
              <button
                onClick={() => submitVisibility(true)}
                disabled={visibilitySaving || (!username && (!usernameValid || usernameAvailable !== true))}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                  letterSpacing: '.1em', textTransform: 'uppercase',
                  padding: '7px 16px',
                  background: (visibilitySaving || (!username && (!usernameValid || usernameAvailable !== true))) ? '#d4c9b0' : 'var(--ah-ink)',
                  color: (visibilitySaving || (!username && (!usernameValid || usernameAvailable !== true))) ? '#a89874' : '#F4ECDC',
                  border: 'none',
                  cursor: (visibilitySaving || (!username && (!usernameValid || usernameAvailable !== true))) ? 'not-allowed' : 'pointer',
                }}
              >
                <EyeIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                {visibilitySaving ? 'Saving…' : 'Confirm — Make Profile Public'}
              </button>
              <button
                onClick={() => { setConfirmingPublic(false); setUsernameInput(suggestedUsername) }}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600,
                  letterSpacing: '.1em', textTransform: 'uppercase',
                  padding: '7px 14px', background: 'transparent',
                  color: '#a89874', border: '1px solid #d4c9b0', cursor: 'pointer',
                }}
              >
                <XMarkIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                Cancel
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Two-Factor Authentication ─────────────────────────────────────── */}
      <div style={cardStyle}>
        <div style={sectionHead}>Two-Factor Authentication</div>
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#666', margin: 0 }}>
          Add an extra layer of security. You'll be asked for a verification code when signing in.
        </p>

        {recoveryCodes && (
          <div style={{ background: '#fff8f0', border: '1px solid #d4a574', padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#9a6b2f' }}>
              Save your recovery codes
            </div>
            <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#6b5436', margin: 0 }}>
              Store these somewhere safe. Each code works once if you lose access to your authenticator. They won't be shown again.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '6px 16px' }}>
              {recoveryCodes.map(c => (
                <div key={c} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '13px', color: 'var(--ah-ink)', letterSpacing: '.08em' }}>{c}</div>
              ))}
            </div>
            <button
              onClick={dismissRecoveryCodes}
              style={{
                alignSelf: 'flex-start',
                fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                letterSpacing: '.1em', textTransform: 'uppercase',
                padding: '6px 14px', background: 'var(--ah-ink)', color: '#F4ECDC', border: 'none', cursor: 'pointer',
              }}
            >
              I've Saved These
            </button>
          </div>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: '1px' }}>
          {MFA_METHODS.map(m => {
            const status = mfa[m.key]
            const isDisabling = disabling === m.key
            return (
              <div key={m.key} style={{
                display: 'flex', flexDirection: 'column', gap: '8px',
                padding: '14px 0', borderBottom: '1px solid #e5ddd0',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', fontWeight: 700, color: 'var(--ah-ink)', letterSpacing: '.04em' }}>
                      {m.label}
                    </div>
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#888', marginTop: '2px' }}>
                      {m.description}
                    </div>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexShrink: 0 }}>
                    {status.enabled ? (
                      <>
                        <span style={{
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                          letterSpacing: '.12em', textTransform: 'uppercase',
                          color: '#4a7c59', background: '#e8f3ec', padding: '3px 8px', border: '1px solid #c3deca',
                        }}>
                          Enabled
                        </span>
                        <button
                          onClick={() => { setDisabling(m.key); setDisablePass('') }}
                          style={{
                            display: 'inline-flex', alignItems: 'center', gap: '6px',
                            fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                            letterSpacing: '.1em', textTransform: 'uppercase',
                            padding: '5px 12px', background: 'transparent',
                            color: 'var(--ah-accent)', border: '1px solid var(--ah-accent)', cursor: 'pointer',
                          }}
                        >
                          <LockOpenIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                          Disable
                        </button>
                      </>
                    ) : (
                      <button
                        onClick={() => m.key === 'totp' ? startTotpEnroll() : submitEnable(m.key)}
                        disabled={m.key === 'totp' && totpStarting}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                          letterSpacing: '.1em', textTransform: 'uppercase',
                          padding: '5px 12px', background: 'var(--ah-ink)',
                          color: '#F4ECDC', border: 'none', cursor: 'pointer',
                        }}
                      >
                        <ShieldCheckIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                        {m.key === 'totp' ? (totpStarting ? 'Starting…' : 'Set Up') : 'Enable'}
                      </button>
                    )}
                  </div>
                </div>

                {status.enabled && status.verified_at && (
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#bbb', letterSpacing: '.06em' }}>
                    Verified {status.verified_at}
                  </div>
                )}

                {m.key === 'totp' && totpEnroll && (
                  <div style={{ background: '#faf7f0', border: '1px solid #e5ddd0', padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: '12px', maxWidth: '420px' }}>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874' }}>
                      Scan with your authenticator app
                    </div>
                    <img src={totpEnroll.qr} alt="Authenticator QR code" width={200} height={200} style={{ alignSelf: 'center', border: '1px solid #e5ddd0', background: '#fff' }} />
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#666' }}>
                      Can't scan? Enter this key manually:
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '12px', color: 'var(--ah-ink)', wordBreak: 'break-all', marginTop: '4px', letterSpacing: '.08em' }}>
                        {totpEnroll.secret}
                      </div>
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', marginTop: '4px' }}>
                      Enter the 6-digit code to confirm
                    </div>
                    <input
                      type="text"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      value={totpCode}
                      onChange={e => setTotpCode(e.target.value.trim())}
                      placeholder="000000"
                      autoFocus
                      style={{ ...input, fontSize: '14px', letterSpacing: '.2em', maxWidth: '160px' }}
                    />
                    {totpError && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)' }}>
                        {totpError}
                      </div>
                    )}
                    <div style={{ display: 'flex', gap: '8px' }}>
                      <button
                        onClick={confirmTotpEnroll}
                        disabled={totpConfirming || !totpCode}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                          letterSpacing: '.1em', textTransform: 'uppercase',
                          padding: '6px 14px', background: totpConfirming ? '#d4c9b0' : 'var(--ah-ink)',
                          color: '#F4ECDC', border: 'none', cursor: totpConfirming ? 'not-allowed' : 'pointer',
                        }}
                      >
                        <ShieldCheckIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                        {totpConfirming ? 'Verifying…' : 'Verify & Enable'}
                      </button>
                      <button
                        onClick={() => { setTotpEnroll(null); setTotpCode(''); setTotpError(null) }}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600,
                          letterSpacing: '.1em', textTransform: 'uppercase',
                          padding: '6px 14px', background: 'transparent',
                          color: '#a89874', border: '1px solid #d4c9b0', cursor: 'pointer',
                        }}
                      >
                        <XMarkIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                        Cancel
                      </button>
                    </div>
                  </div>
                )}

                {m.key === 'totp' && !totpEnroll && totpError && (
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)' }}>
                    {totpError}
                  </div>
                )}

                {isDisabling && (
                  <div style={{ background: '#fff8f6', border: '1px solid #f3d0c8', padding: '12px 14px', display: 'flex', flexDirection: 'column', gap: '8px', maxWidth: '360px' }}>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--ah-accent)' }}>
                      Confirm your password to disable
                    </div>
                    <input
                      type="password"
                      value={disablePass}
                      onChange={e => setDisablePass(e.target.value)}
                      placeholder="Current password"
                      autoFocus
                      style={{ ...input, fontSize: '13px' }}
                    />
                    {errors.mfa_password && (
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-accent)' }}>
                        {errors.mfa_password}
                      </div>
                    )}
                    <div style={{ display: 'flex', gap: '8px' }}>
                      <button
                        onClick={() => submitDisable(m.key)}
                        disabled={disableSaving || !disablePass}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                          letterSpacing: '.1em', textTransform: 'uppercase',
                          padding: '6px 14px', background: disableSaving ? '#d4c9b0' : 'var(--ah-accent)',
                          color: '#fff', border: 'none', cursor: disableSaving ? 'not-allowed' : 'pointer',
                        }}
                      >
                        <ShieldExclamationIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                        {disableSaving ? 'Disabling…' : 'Confirm Disable'}
                      </button>
                      <button
                        onClick={() => { setDisabling(null); setDisablePass('') }}
                        style={{
                          display: 'inline-flex', alignItems: 'center', gap: '6px',
                          fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600,
                          letterSpacing: '.1em', textTransform: 'uppercase',
                          padding: '6px 14px', background: 'transparent',
                          color: '#a89874', border: '1px solid #d4c9b0', cursor: 'pointer',
                        }}
                      >
                        <XMarkIcon style={{ width: '13px', height: '13px', flexShrink: 0 }} />
                        Cancel
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* ── Recent Sign-ins ───────────────────────────────────────────────── */}
      <div style={cardStyle}>
        <div style={sectionHead}>Recent Sign-ins</div>

        {loginHistory.length === 0 ? (
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
            No sign-in history available.
          </p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0' }}>
            {loginHistory.map((entry, i) => (
              <div key={entry.id ?? i} style={{
                display: 'grid', gridTemplateColumns: '1fr auto',
                padding: '10px 0', borderBottom: '1px solid #e5ddd0',
                gap: '12px', alignItems: 'start',
              }}>
                <div>
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: 'var(--ah-ink)', letterSpacing: '.04em' }}>
                    {entry.at}
                  </div>
                  <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#a89874', letterSpacing: '.04em', marginTop: '3px' }}>
                    {entry.ip ?? 'Unknown IP'}
                    {entry.mfa_used && (
                      <span style={{ marginLeft: '8px', color: '#4a7c59' }}>· MFA used</span>
                    )}
                  </div>
                  {entry.ua && (
                    <div style={{
                      fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', color: '#bbb',
                      letterSpacing: '.02em', marginTop: '2px',
                      overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis', maxWidth: '380px',
                    }}>
                      {entry.ua}
                    </div>
                  )}
                </div>
                <span style={{
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                  letterSpacing: '.1em', textTransform: 'uppercase',
                  padding: '3px 8px',
                  background: entry.success ? '#e8f3ec' : '#fdf0ed',
                  color: entry.success ? '#4a7c59' : 'var(--ah-accent)',
                  border: `1px solid ${entry.success ? '#c3deca' : '#f3c4b5'}`,
                  whiteSpace: 'nowrap',
                }}>
                  {entry.success ? 'Success' : 'Failed'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

    </div>
  )
}

// ── Activity Tab ──────────────────────────────────────────────────────────────

const SPECIES_LABELS: Record<string, string> = {
  whitetail_deer: 'Whitetail Deer', mule_deer: 'Mule Deer', turkey: 'Turkey',
  waterfowl: 'Waterfowl', dove: 'Dove', hog: 'Wild Hog', elk: 'Elk',
  bear: 'Black Bear', antelope: 'Antelope', pheasant: 'Pheasant',
  quail: 'Quail', rabbit: 'Rabbit', squirrel: 'Squirrel',
  coyote: 'Coyote / Predator', other: 'Other',
}

const WEAPON_LABELS: Record<string, string> = {
  bow: 'Bow', rifle: 'Rifle', shotgun: 'Shotgun',
  muzzleloader: 'Muzzleloader', pistol: 'Pistol', other: 'Other',
}

function ActivityTab({ events }: { events: ActivityEvent[] }) {
  const mono: React.CSSProperties = { fontFamily: 'JetBrains Mono, monospace' }
  const serif: React.CSSProperties = { fontFamily: 'Crimson Pro, Georgia, serif' }

  if (events.length === 0) {
    return (
      <div style={{ textAlign: 'center', padding: '48px 0' }}>
        <div style={{ ...mono, fontSize: '10px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#ccc', marginBottom: '8px' }}>
          Activity Feed
        </div>
        <p style={{ ...serif, fontSize: '15px', fontStyle: 'italic', color: '#bbb', margin: 0 }}>
          Harvest logs and hunt activity will appear here.
        </p>
      </div>
    )
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1px' }}>
      {events.map((ev, i) => ev.type === 'harvest' ? (
        <div key={i} style={{ background: 'var(--ah-paper)', border: '1px solid #e8e0d0', padding: '14px 16px', display: 'flex', gap: '14px', alignItems: 'flex-start' }}>
          <div style={{ ...mono, fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#b8934a', background: 'var(--ah-ink)', padding: '4px 8px', whiteSpace: 'nowrap', flexShrink: 0 }}>
            Harvest
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ ...mono, fontSize: '11px', fontWeight: 700, color: 'var(--ah-ink)', marginBottom: '2px' }}>
              {SPECIES_LABELS[ev.species ?? ''] ?? ev.species}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center' }}>
              <span style={{ ...mono, fontSize: '9px', color: '#6b7280' }}>{ev.date_label}</span>
              {ev.weapon_type && <span style={{ ...mono, fontSize: '9px', color: '#9ca3af' }}>{WEAPON_LABELS[ev.weapon_type] ?? ev.weapon_type}</span>}
              {ev.weight_lbs && <span style={{ ...mono, fontSize: '9px', color: '#9ca3af' }}>{ev.weight_lbs} lbs</span>}
              {ev.antler_score && <span style={{ ...mono, fontSize: '9px', color: '#9ca3af' }}>Score: {ev.antler_score}</span>}
            </div>
            {ev.notes && <p style={{ ...serif, fontSize: '13px', color: '#6b7280', margin: '4px 0 0', fontStyle: 'italic' }}>{ev.notes}</p>}
          </div>
        </div>
      ) : (
        <div key={i} style={{ background: 'var(--ah-paper)', border: '1px solid #e8e0d0', padding: '14px 16px', display: 'flex', gap: '14px', alignItems: 'flex-start' }}>
          <div style={{ ...mono, fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'var(--ah-ink)', padding: '4px 8px', whiteSpace: 'nowrap', flexShrink: 0 }}>
            {ev.checked_out ? 'Hunt' : 'Check-in'}
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center' }}>
              <span style={{ ...mono, fontSize: '11px', fontWeight: 700, color: 'var(--ah-ink)' }}>{ev.date_label}</span>
              <span style={{ ...mono, fontSize: '9px', color: '#9ca3af' }}>
                {ev.time_label}{ev.checked_out ? ` — ${ev.checked_out}` : ' · still in field'}
              </span>
            </div>
            {ev.notes && <p style={{ ...serif, fontSize: '13px', color: '#6b7280', margin: '4px 0 0', fontStyle: 'italic' }}>{ev.notes}</p>}
          </div>
        </div>
      ))}
    </div>
  )
}
