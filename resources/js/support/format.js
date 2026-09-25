/**
 * Dates, sizes and names as the inbox shows them. Pure functions, so the
 * page logic can be tested without a Control Panel.
 */

/** The Control Panel's locale, the way core formats its own dates. */
export function locale() {
    return globalThis.Statamic?.$config?.get?.('locale') || document?.documentElement?.lang || 'de';
}

function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/**
 * The time column: today only the time, this year day and month, older
 * with the year. The way a mail program lists its inbox.
 */
export function listTime(iso, now = new Date(), lang = locale()) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    if (sameDay(date, now)) {
        return new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit' }).format(date);
    }

    const options = date.getFullYear() === now.getFullYear()
        ? { day: 'numeric', month: 'short' }
        : { day: '2-digit', month: '2-digit', year: 'numeric' };

    return new Intl.DateTimeFormat(lang, options).format(date);
}

/** Day and time in full, for a message header or a tooltip. */
export function fullDateTime(iso, lang = locale()) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat(lang, {
        weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(date);
}

export function fileSize(bytes, lang = locale()) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    const units = ['KB', 'MB', 'GB'];
    let value = n / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${new Intl.NumberFormat(lang, { maximumFractionDigits: value < 10 ? 1 : 0 }).format(value)} ${units[unit]}`;
}

/** "Anna Beispiel", or the address when the sender gave no name. */
export function senderName(message) {
    return (message?.from_name || '').trim() || message?.from_email || '';
}

/** A list of `{email, name}` as one line. */
export function addressLine(addresses) {
    return (addresses || [])
        .map((a) => (a?.name ? `${a.name} <${a.email}>` : a?.email))
        .filter(Boolean)
        .join(', ');
}

/**
 * The presets the snooze menu offers, computed from now: tomorrow at eight,
 * the coming Monday at eight, one week from today at eight.
 */
export function snoozePresets(now = new Date()) {
    const at8 = (date) => {
        const d = new Date(date);
        d.setHours(8, 0, 0, 0);
        return d;
    };

    const tomorrow = at8(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));
    const daysToMonday = ((8 - now.getDay()) % 7) || 7;
    const monday = at8(new Date(now.getFullYear(), now.getMonth(), now.getDate() + daysToMonday));
    const week = at8(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 7));

    return [
        { key: 'tomorrow', label: __('Tomorrow, 8 am'), date: tomorrow },
        { key: 'monday', label: __('Next Monday, 8 am'), date: monday },
        { key: 'week', label: __('In one week'), date: week },
    ];
}

/** The value a `datetime-local` input needs, in local time. */
export function toLocalInput(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
