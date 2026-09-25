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
    // "Last successful": next to "Not reachable", a plain "last fetched"
    // time read as if the fetch had just worked.
    { field: 'last_fetched_at', label: __('Last successful fetch'), visible: true, sortable: true },
];

const rows = computed(() => props.mailboxes);

/** One word for the state of a mailbox, and the colour it gets. */
function state(mailbox) {
    const problem = mailbox.problem;
    const reason = problem ? `${problem.title}. ${problem.text}` : null;

    if (!mailbox.active) return { text: __('Paused'), color: 'default', reason: null };
    if (mailbox.last_error && mailbox.last_error_scope === 'mailbox') {
        const text = {
            auth: __('Login refused'),
            connection: __('Server not reachable'),
            tls: __('Encryption failed'),
            folder: __('Folder missing'),
            quota: __('Mailbox full'),
        }[problem?.code] ?? __('Fetch failing');

        return { text, color: 'red', reason };
    }
    if (mailbox.last_error) return { text: __('With problems'), color: 'amber', reason };
    if (!mailbox.last_fetched_at) return { text: __('Waiting for the first fetch'), color: 'default', reason: null };
    return { text: __('Working'), color: 'green', reason: null };
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
                :description="__('The mailbox you already use, with an app password. Google Workspace, Migadu, manitu, All-Inkl or your own server.')"
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
                <Badge
                    v-tooltip="state(row).reason"
                    pill
                    :color="state(row).color"
                    :text="state(row).text"
                    :data-inbox-mailbox-state="row.id"
                />
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
