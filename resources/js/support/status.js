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
        if (mailbox.last_error && mailbox.last_error_scope === 'mailbox') {
            problems.push({
                key: `mailbox-${mailbox.id}`,
                variant: 'error',
                mailbox,
                heading: __('The mailbox :name cannot be fetched', { name: mailbox.name }),
                text: __('New mail is not arriving here until the connection works again.'),
                detail: mailbox.last_error,
            });
            continue;
        }

        for (const [folder, error] of Object.entries(mailbox.folder_errors ?? {})) {
            problems.push({
                key: `folder-${mailbox.id}-${folder}`,
                variant: 'warning',
                mailbox,
                heading: __('The folder :folder in :name cannot be read', { folder, name: mailbox.name }),
                text: __('The other folders are fetched as usual.'),
                detail: error,
            });
        }
    }

    for (const failure of failures) {
        const mailbox = byId[failure.mailbox_id];
        if (!mailbox) continue;
        problems.push({
            key: `failed-${failure.mailbox_id}-${failure.folder}`,
            variant: 'warning',
            mailbox,
            heading: failure.count === 1
                ? __('One message in :folder (:name) could not be read', { folder: failure.folder, name: mailbox.name })
                : __(':count messages in :folder (:name) could not be read', { count: failure.count, folder: failure.folder, name: mailbox.name }),
            text: __('They were tried three times and are now skipped. Open them in your mail program.'),
            detail: null,
        });
    }

    return problems;
}
