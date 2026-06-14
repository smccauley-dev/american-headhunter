// Phone-number formatting for display + tel: links.
// Mirrors the App\Support\PhoneNumber PHP helper used on the server — keep the
// two in sync. Use these anywhere a stored phone number is shown.

/**
 * Format a phone number for display as +1 (123) 456-7890. Falls back to the
 * original trimmed string for anything that isn't a 10/11-digit US number.
 */
export function formatPhone(raw: string | null | undefined): string {
  const value = (raw ?? '').trim()
  if (value === '') return ''

  let digits = value.replace(/\D+/g, '')
  if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1)
  if (digits.length !== 10) return value

  return `+1 (${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`
}

/**
 * Dialable tel: href value — E.164 (+1XXXXXXXXXX) for US numbers, else the raw
 * digits (preserving a leading +).
 */
export function telHref(raw: string | null | undefined): string {
  const value = (raw ?? '').trim()
  if (value === '') return ''

  const digits = value.replace(/\D+/g, '')
  if (digits.length === 10) return `+1${digits}`
  if (digits.length === 11 && digits.startsWith('1')) return `+${digits}`

  return `${value.startsWith('+') ? '+' : ''}${digits}`
}
