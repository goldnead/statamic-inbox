/** The three states of a conversation, plus snoozed, as the CP names them. */
export const TABS = ['open', 'waiting', 'closed', 'snoozed'];

export function statusLabel(status) {
    return {
        open: __('Open'),
        waiting: __('Waiting'),
        closed: __('Closed'),
        snoozed: __('Snoozed'),
    }[status] ?? status;
}

/** Badge colour per state: open wants attention, closed is done. */
export function statusColor(status) {
    return { open: 'blue', waiting: 'amber', closed: 'green', snoozed: 'purple' }[status] ?? 'default';
}

/** Whether a conversation is snoozed right now. */
export function isSnoozed(conversation, now = new Date()) {
    return Boolean(conversation?.snoozed_until) && new Date(conversation.snoozed_until) > now;
}

/**
 * What the fetch could not do, per mailbox, in words: a mailbox that cannot
 * be reached at all, a folder that cannot be read, messages it gave up on.
 */
export function mailboxProblems(mailboxes = [], failures = []) {
    const problems = [];
    const byId = Object.fromEntries(mailboxes.map((m) => [m.id, m]));

    for (const mailbox of mailboxes) {
        // Worded on the server (ErrorExplainer), cause and fix included.
        if (mailbox.problem) {
            problems.push({
                key: `mailbox-${mailbox.id}`,
                variant: 'error',
                problem: {
                    ...mailbox.problem,
                    text: `${mailbox.problem.text} ${__('Until then no new mail arrives from :name.', { name: mailbox.name })}`,
                },
            });
            continue;
        }

        for (const [folder, problem] of Object.entries(mailbox.folder_problems ?? {})) {
            if (!problem) continue;
            problems.push({
                key: `folder-${mailbox.id}-${folder}`,
                variant: 'warning',
                problem: {
                    ...problem,
                    title: __(':where of :name cannot be read', { where: folderName(folder, mailbox), name: mailbox.name }),
                    text: `${problem.text} ${__('The other folders are fetched as usual.')}`,
                },
            });
        }
    }

    for (const failure of failures) {
        const mailbox = byId[failure.mailbox_id];
        if (!mailbox) continue;
        const where = folderIn(failure.folder, mailbox);
        problems.push({
            key: `failed-${failure.mailbox_id}-${failure.folder}`,
            variant: 'warning',
            dismissible: true,
            signature: `${failure.mailbox_id}:${failure.folder}:${failure.latest ?? failure.count}`,
            problem: {
                code: 'skipped',
                title: failure.count === 1
                    ? __('One message :where of :name could not be read', { where, name: mailbox.name })
                    : __(':count messages :where of :name could not be read', { count: failure.count, where, name: mailbox.name }),
                text: __('They were tried three times and are now skipped. Open them in your mail program.'),
                action: null,
                detail: null,
            },
        });
    }

    return problems;
}

function folderKind(folder, mailbox) {
    if (String(folder).toUpperCase() === 'INBOX' || folder === mailbox.inbox_folder) return 'inbox';
    if (folder === mailbox.sent_folder || /sent|gesendet/i.test(folder)) return 'sent';
    return 'other';
}

/** "Der Posteingang", "Der Gesendet-Ordner", "Der Ordner Archiv": never the IMAP name INBOX. */
export function folderName(folder, mailbox = {}) {
    return {
        inbox: __('The inbox'),
        sent: __('The Sent folder'),
        other: __('The folder :folder', { folder }),
    }[folderKind(folder, mailbox)];
}

/** "im Posteingang", "im Gesendet-Ordner", "im Ordner Archiv". */
export function folderIn(folder, mailbox = {}) {
    return {
        inbox: __('in the inbox'),
        sent: __('in the Sent folder'),
        other: __('in the folder :folder', { folder }),
    }[folderKind(folder, mailbox)];
}

/** Notices hidden by "Ausblenden", until something new happens. */
const DISMISSED = 'inbox.dismissed-notices';

export function dismissedSignatures() {
    try {
        return new Set(JSON.parse(globalThis.localStorage?.getItem(DISMISSED) ?? '[]'));
    } catch {
        return new Set();
    }
}

export function dismissSignature(signature) {
    try {
        const all = dismissedSignatures();
        all.add(signature);
        globalThis.localStorage?.setItem(DISMISSED, JSON.stringify([...all].slice(-50)));
    } catch {
        // Private mode or blocked storage: hidden until the next page load only.
    }
}
