<script setup>
/**
 * The connected mailboxes: address, whether fetching works and when it
 * last ran. Rows come in as a prop (client-mode Listing), as on the
 * Connections screen of statamic-automations.
 */
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    CommandPaletteItem,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';
import { fullDateTime, listTime } from '../../support/format.js';

const props = defineProps({
    setupRequired: { type: String, default: null },
    mailboxes: { type: Array, default: () => [] },
    presets: { type: Object, default: () => ({}) },
    createUrl: { type: String, required: true },
    inboxUrl: { type: String, default: '' },
});

const title = __('Mailboxes');

const columns = [
    { field: 'name', label: __('Name'), visible: true, sortable: true },
    { field: 'state', label: __('Fetching'), visible: true, sortable: false },
    { field: 'last_fetched_at', label: __('Last fetched'), visible: true, sortable: true },
];

const rows = computed(() => props.mailboxes);

/** One word for the state of a mailbox, and the colour it gets. */
function state(mailbox) {
    if (!mailbox.active) return { text: __('Paused'), color: 'default' };
    if (mailbox.last_error && mailbox.last_error_scope === 'mailbox') return { text: __('Not reachable'), color: 'red' };
    if (mailbox.last_error) return { text: __('With problems'), color: 'amber' };
    if (!mailbox.last_fetched_at) return { text: __('Waiting for the first fetch'), color: 'default' };
    return { text: __('Working'), color: 'green' };
}

function reloadPage() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="[title, __('Postfach')]" />

    <div v-if="setupRequired" class="max-w-page mx-auto">
        <Alert variant="error" :text="setupRequired" class="mt-8" />
    </div>

    <div v-else-if="rows.length === 0" class="max-w-page mx-auto" data-inbox-mailboxes-empty>
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="mail-settings" class="size-5 text-gray-500" />
                {{ title }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Connect the mailbox you already use. Mail is fetched every minute and replies go out from the same address.')">
            <EmptyStateItem
                :href="createUrl"
                icon="mail-settings"
                :heading="__('Connect mailbox')"
                :description="__('IMAP to receive, SMTP to send, an app password to sign in.')"
            />
        </EmptyStateMenu>
    </div>

    <div v-else class="max-w-page mx-auto" data-inbox-mailboxes>
        <Header :title="title" icon="mail-settings">
            <CommandPaletteItem
                category="Actions"
                :text="__('Connect mailbox')"
                icon="mail-settings"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button :href="url" :text="text" variant="primary" />
            </CommandPaletteItem>
        </Header>

        <Listing
            :items="rows"
            :columns="columns"
            :allow-bulk-actions="false"
            preferences-prefix="inbox.mailboxes"
            @refreshing="reloadPage"
        >
            <template #cell-name="{ row }">
                <Link :href="row.edit_url" class="font-medium">{{ row.name }}</Link>
                <div class="text-2xs text-gray-500">{{ row.email }}</div>
            </template>
            <template #cell-state="{ row }">
                <Badge pill :color="state(row).color" :text="state(row).text" :data-inbox-mailbox-state="row.id" />
            </template>
            <template #cell-last_fetched_at="{ row }">
                <span class="text-sm" :title="fullDateTime(row.last_fetched_at)">
                    {{ row.last_fetched_at ? listTime(row.last_fetched_at) : __('Never') }}
                </span>
            </template>
            <template #prepended-row-actions="{ row }">
                <DropdownItem :text="__('Edit')" icon="edit" :href="row.edit_url" />
            </template>
        </Listing>
    </div>
</template>
