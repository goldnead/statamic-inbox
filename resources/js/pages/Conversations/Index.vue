<script setup>
/**
 * The inbox: conversations in four tabs (Offen, Wartet, Erledigt,
 * Geschlummert), searched, filtered by mailbox, newest first. A fifth, Neu,
 * holds first contacts from unknown people, with their own actions.
 *
 * Core's Listing in server mode: it asks this page's own URL for JSON (the
 * controller answers `wantsJson` with rows plus `meta.columns`), so search,
 * pagination, the mailbox filter, saved views and column choices all behave
 * like the Entries screen. The tab travels as an additional parameter.
 *
 * Above the list: what the fetch could not do, per mailbox. Without a
 * mailbox there is no list at all, but the way to connect one.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Description,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
    TabList,
    Tabs,
    TabTrigger,
} from '@statamic/cms/ui';

import { fullDateTime, listTime } from '../../support/format.js';
import { dismissedSignatures, dismissSignature, mailboxProblems, statusLabel, TABS } from '../../support/status.js';
import ProblemNotice from '../../components/ProblemNotice.vue';
import HideSenderModal from '../../components/HideSenderModal.vue';
import { firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    setupRequired: { type: String, default: null },
    tab: { type: String, default: 'open' },
    tabCounts: { type: Object, default: () => ({}) },
    columns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    listingUrl: { type: String, default: '' },
    mailboxes: { type: Array, default: () => [] },
    failures: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    canReply: { type: Boolean, default: false },
    canManageMailboxes: { type: Boolean, default: false },
    mailboxesUrl: { type: String, default: '' },
    createMailboxUrl: { type: String, default: '' },
});

const title = __('Postfach');
const hasMailbox = computed(() => props.mailboxes.length > 0);
const problems = computed(() => mailboxProblems(props.mailboxes, props.failures));

// "Ausblenden" hides a skipped-messages notice until a new failure changes it.
const dismissed = ref(dismissedSignatures());
const visibleProblems = computed(() => problems.value.filter((p) => !p.signature || !dismissed.value.has(p.signature)));

function dismiss(notice) {
    dismissSignature(notice.signature);
    dismissed.value = new Set([...dismissed.value, notice.signature]);
}

// On a phone the notices fold to their title line, so the list stays in view.
const narrow = typeof window !== 'undefined' && window.matchMedia?.('(max-width: 640px)').matches;

// ── Tabs ────────────────────────────────────────────────────────────────
const activeTab = ref(TABS.includes(props.tab) ? props.tab : 'open');
const parameters = computed(() => ({ tab: activeTab.value }));

// The tab stays in the address, so a reload or a shared link lands on it.
watch(activeTab, (tab) => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

// ── Row actions ─────────────────────────────────────────────────────────
const listing = ref(null);

// "Neu": take a first contact over, or hide the sender or the whole domain.
async function accept(row) {
    try {
        await axios.post(row.accept_url);
        globalThis.Statamic?.$toast?.success?.(__('Taken over'));
        listing.value?.refresh?.();
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
    }
}

const hiding = ref(null);

function hide(row, scope) {
    hiding.value = { scope, address: row.counterpart_email, url: row.block_url, isGmail: row.is_gmail };
}

async function patch(row, changes, message) {
    try {
        await axios.patch(row.update_url, changes);
        if (message) globalThis.Statamic?.$toast?.success?.(message);
        listing.value?.refresh?.();
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="max-w-page mx-auto" data-inbox-index>
        <Alert v-if="setupRequired" variant="error" :text="setupRequired" class="mt-8" />

        <!-- No mailbox yet: the way to connect one, core's empty state. -->
        <template v-else-if="!hasMailbox">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="mail-inbox-content" class="size-5 text-gray-500" />
                    {{ title }}
                </h1>
            </header>
            <EmptyStateMenu
                v-if="canManageMailboxes"
                :heading="__('Connect your mailbox. Every mail with a person then shows up here as one conversation.')"
                data-inbox-no-mailbox
            >
                <EmptyStateItem
                    :href="createMailboxUrl"
                    icon="mail-settings"
                    :heading="__('Connect mailbox')"
                    :description="__('The mailbox you already use, with an app password. Google Workspace, Migadu, manitu, All-Inkl or your own server.')"
                />
            </EmptyStateMenu>
            <EmptyStateMenu
                v-else
                :heading="__('No mailbox is connected yet. Ask someone who may manage mailboxes to connect one.')"
                data-inbox-no-mailbox
            />
        </template>

        <template v-else>
            <Header :title="title" icon="mail-inbox-content">
                <Button
                    v-if="canManageMailboxes"
                    :href="mailboxesUrl"
                    icon="mail-settings"
                    :text="__('Mailboxes')"
                />
            </Header>

            <div v-if="visibleProblems.length" class="mb-6 space-y-3" data-inbox-problems>
                <ProblemNotice
                    v-for="notice in visibleProblems"
                    :key="notice.key"
                    :problem="notice.problem"
                    :variant="notice.variant"
                    :compact="narrow"
                    :dismissible="notice.dismissible"
                    @dismiss="dismiss(notice)"
                />
            </div>

            <Tabs v-model="activeTab" class="mb-4">
                <TabList>
                    <TabTrigger v-for="t in TABS" :key="t" :name="t" :data-inbox-tab="t">
                        {{ statusLabel(t) }}
                        <Badge
                            v-if="(tabCounts[t] ?? 0) > 0 && t !== 'closed'"
                            pill
                            class="ms-1.5"
                            :text="String(tabCounts[t])"
                        />
                    </TabTrigger>
                </TabList>
            </Tabs>

            <Listing
                ref="listing"
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :additional-parameters="parameters"
                :allow-bulk-actions="false"
                :allow-presets="false"
                preferences-prefix="inbox.conversations"
                sort-column="last_message_at"
                sort-direction="desc"
                push-query
            >
                <template #cell-counterpart="{ row }">
                    <Link :href="row.show_url" class="flex items-center gap-2 min-w-0" :data-inbox-row="row.id">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="row.unread ? 'bg-primary' : 'bg-transparent'"
                            :aria-label="row.unread ? __('Unread') : undefined"
                            :data-inbox-unread="row.unread ? 'true' : 'false'"
                        />
                        <span class="min-w-0">
                            <span class="block truncate" :class="row.unread ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-900 dark:text-gray-100'">{{ row.counterpart }}</span>
                            <span v-if="row.counterpart !== row.counterpart_email" class="block truncate text-2xs text-gray-500">{{ row.counterpart_email }}</span>
                        </span>
                    </Link>
                </template>
                <template #cell-subject="{ row }">
                    <Link :href="row.show_url" class="block min-w-0 max-w-xl">
                        <span class="flex items-center gap-2">
                            <span class="truncate" :class="row.unread ? 'font-semibold text-gray-900 dark:text-white' : ''">{{ row.subject || __('(no subject)') }}</span>
                            <Badge v-if="row.has_send_error" pill color="red" :text="__('Not sent')" />
                        </span>
                        <span class="block truncate text-sm text-gray-500 dark:text-gray-400">{{ row.excerpt }}</span>
                    </Link>
                </template>
                <template #cell-mailbox="{ row }">
                    <span class="text-sm">{{ row.mailbox }}</span>
                </template>
                <template #cell-last_message_at="{ row }">
                    <span
                        class="whitespace-nowrap text-sm"
                        :class="row.unread ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400'"
                        :title="fullDateTime(row.last_message_at)"
                    >{{ listTime(row.last_message_at) }}</span>
                    <span v-if="activeTab === 'snoozed' && row.snoozed_until" class="block whitespace-nowrap text-2xs text-gray-500">
                        {{ __('until :date', { date: listTime(row.snoozed_until) }) }}
                    </span>
                </template>
                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('Open conversation')" icon="mail" :href="row.show_url" />
                    <template v-if="canReply && row.status === 'new'">
                        <DropdownItem :text="__('Accept')" icon="checkmark" data-inbox-accept @click="accept(row)" />
                        <DropdownItem :text="__('Hide sender')" icon="eye-closed" variant="destructive" data-inbox-hide-sender @click="hide(row, 'sender')" />
                        <DropdownItem v-if="row.can_hide_domain" :text="__('Hide domain')" icon="eye-closed" variant="destructive" data-inbox-hide-domain @click="hide(row, 'domain')" />
                    </template>
                    <template v-else-if="canReply">
                        <DropdownItem
                            v-if="row.status !== 'closed'"
                            :text="__('Mark as done')"
                            icon="checkmark"
                            @click="patch(row, { status: 'closed', snoozed_until: null }, __('Marked as done'))"
                        />
                        <DropdownItem
                            v-else
                            :text="__('Reopen')"
                            icon="mail-inbox-content"
                            @click="patch(row, { status: 'open' }, __('Moved to open'))"
                        />
                        <DropdownItem
                            v-if="!row.unread"
                            :text="__('Mark as unread')"
                            icon="mail"
                            @click="patch(row, { unread: true }, null)"
                        />
                        <DropdownItem
                            v-else
                            :text="__('Mark as read')"
                            icon="mail-check"
                            @click="patch(row, { unread: false }, null)"
                        />
                    </template>
                </template>
            </Listing>

            <Description
                v-if="activeTab === 'snoozed'"
                class="mt-4"
                :text="__('Snoozed conversations come back to their list at the chosen time.')"
            />
            <Description
                v-if="activeTab === 'new'"
                class="mt-4"
                data-inbox-new-hint
                :text="__('First contacts from people you have not written to yet. Newsletters, invoices and automatic mails do not arrive here at all; you find them in your mail program.')"
            />
        </template>
    </div>

    <HideSenderModal
        :open="hiding !== null"
        :scope="hiding?.scope ?? 'sender'"
        :address="hiding?.address ?? ''"
        :is-gmail="hiding?.isGmail ?? false"
        :url="hiding?.url ?? ''"
        @update:open="!$event && (hiding = null)"
        @hidden="listing?.refresh?.()"
    />
</template>
