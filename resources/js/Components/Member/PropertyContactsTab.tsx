import { useForm, router } from '@inertiajs/react'
import { useState } from 'react'
import { Section, UsersIcon, IdentificationIcon, INK, ACCENT } from './PropertyChrome'

interface PartyContact {
  name: string
  phone: string | null
  phone_formatted: string
  email: string | null
  role_label?: string
  manager_id?: string
}

export interface ContactDirectory {
  landowner: PartyContact | null
  managers: PartyContact[]
}

export interface EligibleManager {
  id: string
  name: string
  role_label: string
}

export interface EditableContact {
  id: string
  contact_type: string
  label: string | null
  name: string | null
  organization: string | null
  phone: string | null
  email: string | null
  address: string | null
  notes: string | null
}

const input: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
}

const label: React.CSSProperties = {
  display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600,
  letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginBottom: '5px',
}

const ghostBtn: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em',
  textTransform: 'uppercase', padding: '7px 13px', background: 'transparent',
  color: INK, border: '1px solid #d4c9b0', cursor: 'pointer',
}

const inkBtn: React.CSSProperties = { ...ghostBtn, background: INK, color: '#F4ECDC', borderColor: INK }
const dangerBtn: React.CSSProperties = { ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }

type ContactForm = Omit<EditableContact, 'id'>

const emptyContact = (type: string): ContactForm => ({
  contact_type: type, label: '', name: '', organization: '', phone: '', email: '', address: '', notes: '',
})

export default function PropertyContactsTab({ propertyId, directory, eligibleManagers, contacts, contactTypes }: {
  propertyId: string
  directory: ContactDirectory
  eligibleManagers: EligibleManager[]
  contacts: EditableContact[]
  contactTypes: Record<string, string>
}) {
  const typeKeys = Object.keys(contactTypes)
  const [selectedManager, setSelectedManager] = useState('')
  const [adding, setAdding] = useState(false)
  const [editingId, setEditingId] = useState<string | null>(null)

  const form = useForm<ContactForm>(emptyContact(typeKeys[0]))

  function addManagerContact() {
    if (!selectedManager) return
    router.post(`/member/properties/${propertyId}/manager-contacts`, { manager_id: selectedManager }, {
      preserveScroll: true, onSuccess: () => setSelectedManager(''),
    })
  }
  function removeManagerContact(managerId: string) {
    if (!confirm('Remove this manager from the contact list?')) return
    router.delete(`/member/properties/${propertyId}/manager-contacts/${managerId}`, { preserveScroll: true })
  }

  function startAdd() { form.setData(emptyContact(typeKeys[0])); setAdding(true); setEditingId(null) }
  function startEdit(c: EditableContact) {
    form.setData({
      contact_type: c.contact_type, label: c.label ?? '', name: c.name ?? '', organization: c.organization ?? '',
      phone: c.phone ?? '', email: c.email ?? '', address: c.address ?? '', notes: c.notes ?? '',
    })
    setEditingId(c.id); setAdding(false)
  }
  function cancelForm() { setAdding(false); setEditingId(null); form.clearErrors() }

  function submitContact(e: React.FormEvent) {
    e.preventDefault()
    if (editingId) {
      form.put(`/member/properties/${propertyId}/contacts/${editingId}`, { preserveScroll: true, onSuccess: cancelForm })
    } else {
      form.post(`/member/properties/${propertyId}/contacts`, { preserveScroll: true, onSuccess: cancelForm })
    }
  }
  function removeContact(id: string) {
    if (!confirm('Delete this contact?')) return
    router.delete(`/member/properties/${propertyId}/contacts/${id}`, { preserveScroll: true })
  }

  const partyCard = (c: PartyContact, kind: string, onRemove?: () => void) => (
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px', padding: '12px 0', borderTop: '1px solid #e5ddd0' }}>
      <div>
        <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: INK }}>
          {c.name}
          <span style={{ fontFamily: 'var(--mono)', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', marginLeft: '9px', borderRadius: '9999px', background: '#1e40af', color: '#fff' }}>
            {c.role_label ?? kind}
          </span>
        </div>
        <div style={{ fontFamily: 'var(--mono)', fontSize: '11px', color: '#6b5e50', marginTop: '4px' }}>
          {[c.phone_formatted, c.email].filter(Boolean).join(' · ') || '—'}
        </div>
      </div>
      {onRemove && <button type="button" onClick={onRemove} style={{ ...dangerBtn, flexShrink: 0 }}>Remove</button>}
    </div>
  )

  return (
    <>
      <Section title="Landowner & Managers" icon={<UsersIcon />} description="The landowner and managers shown to active hunters as on-site field contacts.">
        {directory.landowner && partyCard(directory.landowner, 'Landowner')}
        {directory.managers.map(m => (
          <div key={m.manager_id}>{partyCard(m, 'Manager', m.manager_id ? () => removeManagerContact(m.manager_id!) : undefined)}</div>
        ))}
        {!directory.landowner && directory.managers.length === 0 && (
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
            No landowner on file.
          </p>
        )}

        {eligibleManagers.length > 0 && (
          <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginTop: '16px', borderTop: '1px solid #e5ddd0', paddingTop: '16px' }}>
            <select value={selectedManager} onChange={e => setSelectedManager(e.target.value)} style={{ ...input, maxWidth: '340px' }}>
              <option value="">— Add a manager as a field contact —</option>
              {eligibleManagers.map(m => <option key={m.id} value={m.id}>{m.name} ({m.role_label})</option>)}
            </select>
            <button type="button" onClick={addManagerContact} disabled={!selectedManager} style={{ ...inkBtn, opacity: selectedManager ? 1 : 0.5 }}>Add</button>
          </div>
        )}
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#6b5e50', marginTop: '12px', lineHeight: 1.5 }}>
          Managers added here are shown to active hunters as on-site contacts. Grant the role on the Team tab first.
        </p>
      </Section>

      <Section
        title="Emergency & Local Contacts"
        icon={<IdentificationIcon />}
        description="Local emergency and service numbers shown to hunters on this property — sheriff, game warden, nearest hospital, and the like."
        action={!adding && editingId === null ? (
          <button type="button" onClick={startAdd} style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 16px', background: INK, color: '#F4ECDC', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}>+ Add Contact</button>
        ) : undefined}
      >
        {(adding || editingId !== null) && (
          <form onSubmit={submitContact} style={{ border: `1px solid ${ACCENT}`, background: '#fff', padding: '18px 16px', marginBottom: '18px', display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <label style={label}>Type</label>
                <select value={form.data.contact_type} onChange={e => form.setData('contact_type', e.target.value)} style={input}>
                  {typeKeys.map(k => <option key={k} value={k}>{contactTypes[k]}</option>)}
                </select>
              </div>
              {form.data.contact_type === 'other' && (
                <div>
                  <label style={label}>Label</label>
                  <input type="text" value={form.data.label ?? ''} onChange={e => form.setData('label', e.target.value)} style={input} maxLength={120} placeholder="e.g. Nearest Vet" />
                </div>
              )}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <label style={label}>Name</label>
                <input type="text" value={form.data.name ?? ''} onChange={e => form.setData('name', e.target.value)} style={input} maxLength={160} />
              </div>
              <div>
                <label style={label}>Organization</label>
                <input type="text" value={form.data.organization ?? ''} onChange={e => form.setData('organization', e.target.value)} style={input} maxLength={160} />
              </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <label style={label}>Phone</label>
                <input type="text" value={form.data.phone ?? ''} onChange={e => form.setData('phone', e.target.value)} style={input} maxLength={40} placeholder="(555) 123-4567" />
              </div>
              <div>
                <label style={label}>Email</label>
                <input type="email" value={form.data.email ?? ''} onChange={e => form.setData('email', e.target.value)} style={input} maxLength={160} />
                {form.errors.email && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{form.errors.email}</div>}
              </div>
            </div>
            <div>
              <label style={label}>Address</label>
              <input type="text" value={form.data.address ?? ''} onChange={e => form.setData('address', e.target.value)} style={input} maxLength={255} />
            </div>
            <div>
              <label style={label}>Notes</label>
              <textarea rows={2} value={form.data.notes ?? ''} onChange={e => form.setData('notes', e.target.value)} style={{ ...input, resize: 'vertical' }} maxLength={500} />
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <button type="submit" disabled={form.processing} style={{ ...inkBtn, padding: '9px 22px', fontSize: '10px', opacity: form.processing ? 0.7 : 1 }}>
                {form.processing ? 'Saving…' : editingId ? 'Save Contact' : 'Add Contact'}
              </button>
              <button type="button" onClick={cancelForm} style={{ ...ghostBtn, padding: '9px 22px', fontSize: '10px' }}>Cancel</button>
            </div>
          </form>
        )}

        {contacts.length === 0 && !adding && editingId === null ? (
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
            No emergency or local contacts yet.
          </p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            {contacts.map((c, i) => (
              <div key={c.id} style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px', padding: '12px 0', borderTop: i === 0 ? 'none' : '1px solid #e5ddd0' }}>
                <div>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: ACCENT, marginBottom: '4px' }}>
                    {c.contact_type === 'other' ? (c.label || 'Other Contact') : (contactTypes[c.contact_type] ?? 'Contact')}
                  </div>
                  <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: INK }}>
                    {[c.name, c.organization].filter(Boolean).join(' · ') || '—'}
                  </div>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '11px', color: '#6b5e50', marginTop: '4px' }}>
                    {[c.phone, c.email, c.address].filter(Boolean).join(' · ') || '—'}
                  </div>
                  {c.notes && <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#6b5e50', marginTop: '4px' }}>{c.notes}</div>}
                </div>
                <div style={{ display: 'flex', gap: '6px', flexShrink: 0 }}>
                  <button type="button" onClick={() => startEdit(c)} style={ghostBtn}>Edit</button>
                  <button type="button" onClick={() => removeContact(c.id)} style={dangerBtn}>Delete</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>
    </>
  )
}
