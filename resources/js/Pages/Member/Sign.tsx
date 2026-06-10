import React, { useState } from 'react'
import { Head, useForm, usePage } from '@inertiajs/react'

interface Signer {
  name: string
  role: string
  status: string
  signed_at: string | null
}

interface LeaseProps {
  id: string
  status: string
  start_date: string
  end_date: string
  total_price: string
  property: {
    title: string
    county: string
    state: string
    acres: string | number
  } | null
}

interface Props {
  lease: LeaseProps
  request_id?: string
  signers: Signer[]
  already_signed: boolean
}

export default function Sign({ lease, request_id, signers, already_signed }: Props) {
  const { props } = usePage<{ flash?: { success?: string; info?: string } }>()
  const flash = props.flash ?? {}

  const { data, setData, post, processing, errors } = useForm({
    request_id: request_id ?? '',
    full_name: '',
    agreed: false as boolean,
  })

  const allSigned = signers.every((s) => s.status === 'signed')

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    post(`/member/leases/${lease.id}/sign`)
  }

  return (
    <>
      <Head title="Sign Lease Agreement" />

      <div style={{ minHeight: '100vh', background: '#fafaf9', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-start', paddingTop: '48px', paddingBottom: '64px' }}>
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '32px' }}>
          <div style={{ fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '8px' }}>
            American Headhunter
          </div>
          <h1 style={{ fontSize: '24px', fontWeight: '700', color: '#0A1512', margin: '0 0 4px' }}>
            Lease Agreement
          </h1>
          <p style={{ fontSize: '14px', color: '#888', margin: 0 }}>
            Review the terms below and sign to confirm your hunting lease.
          </p>
        </div>

        <div style={{ width: '100%', maxWidth: '640px', padding: '0 16px' }}>

          {/* Flash messages */}
          {flash.success && (
            <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '4px', padding: '12px 16px', marginBottom: '20px', color: '#15803d', fontSize: '14px' }}>
              {flash.success}
            </div>
          )}
          {flash.info && (
            <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: '4px', padding: '12px 16px', marginBottom: '20px', color: '#1d4ed8', fontSize: '14px' }}>
              {flash.info}
            </div>
          )}

          {/* Lease Summary */}
          <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', marginBottom: '20px', overflow: 'hidden' }}>
            <div style={{ background: '#0A1512', padding: '14px 20px' }}>
              <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '4px' }}>Lease Agreement</div>
              <div style={{ fontSize: '16px', fontWeight: '700', color: '#fff' }}>
                {lease.property?.title ?? 'Hunting Property'}
              </div>
              {lease.property && (
                <div style={{ fontSize: '12px', color: '#aaa', marginTop: '2px' }}>
                  {lease.property.county} County, {lease.property.state}
                  {lease.property.acres ? ` · ${Number(lease.property.acres).toLocaleString()} acres` : ''}
                </div>
              )}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '0', borderTop: '1px solid #e5e0d8' }}>
              <div style={{ padding: '14px 20px', borderRight: '1px solid #e5e0d8' }}>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#888', marginBottom: '4px' }}>Start Date</div>
                <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>{lease.start_date}</div>
              </div>
              <div style={{ padding: '14px 20px', borderRight: '1px solid #e5e0d8' }}>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#888', marginBottom: '4px' }}>End Date</div>
                <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>{lease.end_date}</div>
              </div>
              <div style={{ padding: '14px 20px' }}>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#888', marginBottom: '4px' }}>Total Price</div>
                <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>${lease.total_price}</div>
              </div>
            </div>
          </div>

          {/* Signing Status */}
          <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', marginBottom: '20px', padding: '16px 20px' }}>
            <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#888', marginBottom: '12px' }}>
              Signatures Required
            </div>
            {signers.map((signer, i) => {
              const isSigned = signer.status === 'signed'
              return (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 0', borderBottom: i < signers.length - 1 ? '1px solid #f0ece6' : 'none' }}>
                  <div style={{ width: '28px', height: '28px', borderRadius: '50%', background: isSigned ? '#f0fdf4' : '#fff7ed', border: `2px solid ${isSigned ? '#15803d' : '#d97706'}`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                    <span style={{ fontSize: '14px', color: isSigned ? '#15803d' : '#d97706', fontWeight: '700' }}>
                      {isSigned ? '✓' : '○'}
                    </span>
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: '13px', fontWeight: '600', color: '#1a1a1a' }}>{signer.name}</div>
                    <div style={{ fontSize: '11px', color: '#888', fontFamily: 'monospace' }}>{signer.role}</div>
                  </div>
                  <div style={{ fontSize: '11px', fontFamily: 'monospace', fontWeight: '700', textTransform: 'uppercase', letterSpacing: '.06em', color: isSigned ? '#15803d' : '#d97706' }}>
                    {isSigned ? 'Signed' : 'Pending'}
                  </div>
                </div>
              )
            })}
          </div>

          {/* Already signed state */}
          {already_signed && (
            <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '4px', padding: '20px', textAlign: 'center', color: '#15803d' }}>
              <div style={{ fontSize: '20px', marginBottom: '8px' }}>✓</div>
              <div style={{ fontSize: '15px', fontWeight: '600', marginBottom: '4px' }}>You have already signed this lease.</div>
              {allSigned
                ? <div style={{ fontSize: '13px', color: '#166534' }}>All parties have signed — your lease is now active.</div>
                : <div style={{ fontSize: '13px', color: '#166534' }}>Waiting for the landowner to countersign.</div>
              }
            </div>
          )}

          {/* Signature form — only shown when not yet signed */}
          {!already_signed && (
            <form onSubmit={handleSubmit}>
              <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '20px', marginBottom: '16px' }}>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#888', marginBottom: '16px' }}>
                  Your Signature
                </div>

                <div style={{ marginBottom: '16px' }}>
                  <label style={{ display: 'block', fontSize: '13px', fontWeight: '600', color: '#1a1a1a', marginBottom: '6px' }}>
                    Type your full legal name to sign
                  </label>
                  <input
                    type="text"
                    value={data.full_name}
                    onChange={(e) => setData('full_name', e.target.value)}
                    placeholder="Your full legal name"
                    style={{
                      width: '100%',
                      padding: '10px 14px',
                      border: errors.full_name ? '1px solid #b91c1c' : '1px solid #d1d5db',
                      borderRadius: '4px',
                      fontSize: '15px',
                      fontStyle: 'italic',
                      color: '#0A1512',
                      background: '#fafaf9',
                      boxSizing: 'border-box',
                    }}
                  />
                  {errors.full_name && (
                    <div style={{ fontSize: '12px', color: '#b91c1c', marginTop: '4px' }}>{errors.full_name}</div>
                  )}
                </div>

                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', padding: '14px', background: '#fafaf9', borderRadius: '4px', border: '1px solid #e5e0d8' }}>
                  <input
                    type="checkbox"
                    id="agreed"
                    checked={data.agreed}
                    onChange={(e) => setData('agreed', e.target.checked)}
                    style={{ marginTop: '2px', width: '16px', height: '16px', flexShrink: 0, accentColor: '#C84C21' }}
                  />
                  <label htmlFor="agreed" style={{ fontSize: '13px', color: '#444', lineHeight: '1.5', cursor: 'pointer' }}>
                    I, <strong>{data.full_name || '[your name]'}</strong>, agree to the terms of this hunting lease agreement
                    for the period {lease.start_date} through {lease.end_date}, for the total amount of ${lease.total_price}.
                    I understand this constitutes a legally binding electronic signature under the ESIGN Act.
                  </label>
                </div>
                {errors.agreed && (
                  <div style={{ fontSize: '12px', color: '#b91c1c', marginTop: '4px' }}>{errors.agreed}</div>
                )}
              </div>

              <button
                type="submit"
                disabled={processing || !data.full_name.trim() || !data.agreed}
                style={{
                  width: '100%',
                  padding: '14px',
                  background: (!data.full_name.trim() || !data.agreed) ? '#d1d5db' : '#C84C21',
                  color: '#fff',
                  border: 'none',
                  borderRadius: '4px',
                  fontSize: '15px',
                  fontWeight: '700',
                  letterSpacing: '.04em',
                  cursor: (!data.full_name.trim() || !data.agreed) ? 'not-allowed' : 'pointer',
                  transition: 'background 0.15s',
                }}
              >
                {processing ? 'Signing…' : 'Sign Lease Agreement'}
              </button>

              <p style={{ fontSize: '11px', color: '#aaa', textAlign: 'center', marginTop: '12px', lineHeight: '1.6' }}>
                Your signature is recorded with your account ID, timestamp, and IP address.
                This constitutes a legally binding agreement under the U.S. ESIGN Act (15 U.S.C. § 7001).
              </p>
            </form>
          )}

        </div>
      </div>
    </>
  )
}
