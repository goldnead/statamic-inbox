/**
 * Reading a rejected axios response without losing what the server said
 * (the same three helpers as statamic-automations).
 *
 *   - `errorBag(e)`        Laravel's `errors` map, one string per key, for the field it belongs to
 *   - `errorMessages(e)`   everything the server said, as a flat list
 *   - `firstMessage(e, f)` one line for a toast or an inline alert
 */

function body(e) {
    return e?.response?.data ?? null;
}

export function errorBag(e) {
    const errors = body(e)?.errors;

    if (!errors || typeof errors !== 'object') return {};

    return Object.fromEntries(
        Object.entries(errors)
            .map(([key, value]) => [key, Array.isArray(value) ? value[0] : value])
            .filter(([, message]) => Boolean(message)),
    );
}

export function errorMessages(e) {
    const data = body(e);
    if (!data) return [];

    const fromBag = Object.values(errorBag(e));
    const generic = fromBag.length === 0 && data.message ? [data.message] : [];

    return [...fromBag, ...generic];
}

export function firstMessage(e, fallback = null) {
    return errorMessages(e)[0] ?? fallback;
}
