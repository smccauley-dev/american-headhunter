import { InputHTMLAttributes } from 'react';

interface AuthInputProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string;
}

export default function AuthInput({ label, error, id, ...props }: AuthInputProps) {
    const inputId = id ?? label.toLowerCase().replace(/\s+/g, '-');

    return (
        <div style={{ marginBottom: 20 }}>
            <label htmlFor={inputId} style={{
                display: 'block', fontFamily: "'JetBrains Mono', monospace",
                fontSize: 10, letterSpacing: '0.18em', textTransform: 'uppercase',
                color: '#4a5440', marginBottom: 6,
            }}>
                {label}
            </label>
            <input
                id={inputId}
                style={{
                    width: '100%', padding: '10px 14px',
                    background: '#f4ecdc', border: `1px solid ${error ? '#c84c21' : '#a89874'}`,
                    borderRadius: 0, fontSize: 16, fontFamily: "'Crimson Pro', Georgia, serif",
                    color: '#0a1512', outline: 'none',
                    transition: 'border-color 0.15s',
                }}
                onFocus={e => (e.target.style.borderColor = '#0a1512')}
                onBlur={e => (e.target.style.borderColor = error ? '#c84c21' : '#a89874')}
                {...props}
            />
            {error && (
                <p style={{ marginTop: 4, fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#c84c21' }}>
                    {error}
                </p>
            )}
        </div>
    );
}
