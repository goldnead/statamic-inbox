/*
 * The single Control Panel entry. Pages are registered under the `inbox::`
 * prefix inside Statamic.booting, because `inertia` belongs to the CP runtime
 * this bundle is externalised against. The names must read exactly as the
 * controllers render them.
 */
import { registerIconSetFromStrings } from '@statamic/cms/ui';
import ConversationsIndex from './pages/Conversations/Index.vue';
import ConversationsShow from './pages/Conversations/Show.vue';
import MailboxesIndex from './pages/Mailboxes/Index.vue';
import MailboxesEdit from './pages/Mailboxes/Edit.vue';

// Core's 548 icons have no paperclip, the one sign everybody reads as
// "attachment". Drawn in core's style (16px grid, 1px round stroke) and used
// as `inbox::paperclip`.
registerIconSetFromStrings('inbox', {
    paperclip: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 16"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M13.6 7.4 8.2 12.8a3.4 3.4 0 0 1-4.8-4.8l5.7-5.7a2.2 2.2 0 0 1 3.1 3.1L6.5 11.1a1 1 0 0 1-1.4-1.4l5-5"/></svg>',
});

Statamic.booting(() => {
    Statamic.$inertia.register('inbox::Conversations/Index', ConversationsIndex);
    Statamic.$inertia.register('inbox::Conversations/Show', ConversationsShow);
    Statamic.$inertia.register('inbox::Mailboxes/Index', MailboxesIndex);
    Statamic.$inertia.register('inbox::Mailboxes/Edit', MailboxesEdit);
});
