/**
 * The mailbox form's rules, apart from the page so they can be tested.
 *
 * The password never comes back from the server: the form starts with it
 * empty and `has_password` tells whether one is stored. Empty on save keeps
 * the stored one, unless host, port or login changed: then the stored
 * password would go to another server, and the server refuses that. The form
 * asks for it up front instead of after a failed save.
 */

/** The settings that decide where the stored password is sent (MailboxesController::PASSWORD_BOUND). */
export const PASSWORD_BOUND = ['imap_host', 'smtp_host', 'username', 'imap_port', 'smtp_port'];

export const CUSTOM = 'custom';

export function fromMailbox(mailbox = {}) {
    return {
        name: mailbox.name ?? '',
        email: mailbox.email ?? '',
        from_name: mailbox.from_name ?? '',
        imap_host: mailbox.imap_host ?? '',
        imap_port: mailbox.imap_port ?? 993,
        imap_encryption: mailbox.imap_encryption ?? 'ssl',
        username: mailbox.username ?? '',
        password: '',
        smtp_host: mailbox.smtp_host ?? '',
        smtp_port: mailbox.smtp_port ?? 587,
        smtp_encryption: mailbox.smtp_encryption ?? 'tls',
        inbox_folder: mailbox.inbox_folder ?? 'INBOX',
        sent_folder: mailbox.sent_folder ?? '',
        append_sent: mailbox.append_sent ?? true,
        import_since: (mailbox.import_since ?? '').slice(0, 10),
        active: mailbox.active ?? true,
        skip_bulk: mailbox.skip_bulk ?? true,
        // One per line in the form, a list for the API.
        aliases: (mailbox.aliases ?? []).join('\n'),
    };
}

/** The aliases field, one address per line (commas work too), as a list. */
export function aliasList(text) {
    return String(text ?? '')
        .split(/[\n,;]+/)
        .map((a) => a.trim())
        .filter(Boolean);
}

const norm = (value) => String(value ?? '').trim().toLowerCase();

/** Whether the form points the stored password somewhere else. */
export function bindingChanged(form, stored) {
    return PASSWORD_BOUND.some((key) => norm(form[key]) !== norm(stored[key]));
}

export function passwordRequired(form, stored, isNew) {
    if (isNew || !stored.has_password) return true;
    return bindingChanged(form, stored);
}

export function isGmail(host) {
    const h = norm(host).replace(/\.$/, '');
    return ['gmail.com', 'googlemail.com'].some((d) => h === d || h.endsWith(`.${d}`));
}

/** The preset whose hosts the form carries, or `custom`. */
export function detectPreset(form, presets = {}) {
    for (const [key, preset] of Object.entries(presets)) {
        if (preset.imap_host && norm(preset.imap_host) === norm(form.imap_host) && norm(preset.smtp_host) === norm(form.smtp_host)) {
            return key;
        }
    }

    return CUSTOM;
}

/**
 * The form after picking a provider: servers, ports and encryption from the
 * preset, the Sent-folder copy off for Gmail. Hosts a preset leaves empty
 * (All-Inkl names a server per customer) keep what was typed.
 */
export function applyPreset(form, preset) {
    if (!preset) return { ...form };

    const next = {
        ...form,
        imap_port: preset.imap_port ?? form.imap_port,
        imap_encryption: preset.imap_encryption ?? form.imap_encryption,
        smtp_port: preset.smtp_port ?? form.smtp_port,
        smtp_encryption: preset.smtp_encryption ?? form.smtp_encryption,
    };
    if (preset.imap_host) next.imap_host = preset.imap_host;
    if (preset.smtp_host) next.smtp_host = preset.smtp_host;
    next.append_sent = !isGmail(next.imap_host) && !isGmail(next.smtp_host);
    if (!next.username && next.email) next.username = next.email;

    return next;
}

/** What the API gets. Empty optional fields go as null, ports as numbers. */
export function payload(form) {
    const number = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

    return {
        name: form.name,
        email: form.email,
        from_name: form.from_name || null,
        imap_host: form.imap_host,
        imap_port: number(form.imap_port),
        imap_encryption: form.imap_encryption,
        username: form.username,
        password: form.password,
        smtp_host: form.smtp_host,
        smtp_port: number(form.smtp_port),
        smtp_encryption: form.smtp_encryption,
        inbox_folder: form.inbox_folder || null,
        sent_folder: form.sent_folder || null,
        append_sent: Boolean(form.append_sent),
        import_since: form.import_since || null,
        active: Boolean(form.active),
        skip_bulk: form.skip_bulk === undefined ? true : Boolean(form.skip_bulk),
        aliases: aliasList(form.aliases),
    };
}

/** The connection-test request: only server and login, the password only when typed. */
export function testPayload(form) {
    const p = payload(form);
    const values = {
        imap_host: p.imap_host,
        imap_port: p.imap_port,
        imap_encryption: p.imap_encryption,
        username: p.username,
        smtp_host: p.smtp_host,
        smtp_port: p.smtp_port,
        smtp_encryption: p.smtp_encryption,
        email: p.email || null,
    };
    if (form.password) values.password = form.password;

    return values;
}

/** Which tab each field lives on, to jump to the first one with an error. */
export const TAB_FIELDS = {
    account: ['name', 'email', 'username', 'password'],
    servers: ['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption'],
    folders: ['inbox_folder', 'sent_folder', 'append_sent', 'import_since', 'active'],
    filter: ['skip_bulk', 'aliases'],
};

export function tabsWithErrors(errors) {
    const tabs = new Set();
    for (const [tab, keys] of Object.entries(TAB_FIELDS)) {
        // `aliases.0` belongs to `aliases`.
        if (Object.keys(errors ?? {}).some((k) => keys.includes(k.split('.')[0]))) tabs.add(tab);
    }

    return tabs;
}
