/*
 * The single Control Panel entry. Pages are registered here under the
 * `inbox::` prefix, inside Statamic.booting, because `inertia` belongs to the
 * CP runtime this bundle is externalised against.
 */
Statamic.booting(() => {
    // inbox::Conversations/Index, inbox::Conversations/Show and
    // inbox::Mailboxes/* arrive with the Control Panel screens.
});
