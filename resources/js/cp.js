/*
 * The single Control Panel entry. Pages are registered under the `inbox::`
 * prefix inside Statamic.booting, because `inertia` belongs to the CP runtime
 * this bundle is externalised against. The names must read exactly as the
 * controllers render them.
 */
import ConversationsIndex from './pages/Conversations/Index.vue';
import ConversationsShow from './pages/Conversations/Show.vue';
import MailboxesIndex from './pages/Mailboxes/Index.vue';
import MailboxesEdit from './pages/Mailboxes/Edit.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('inbox::Conversations/Index', ConversationsIndex);
    Statamic.$inertia.register('inbox::Conversations/Show', ConversationsShow);
    Statamic.$inertia.register('inbox::Mailboxes/Index', MailboxesIndex);
    Statamic.$inertia.register('inbox::Mailboxes/Edit', MailboxesEdit);
});
