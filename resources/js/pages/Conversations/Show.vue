<script setup>
/**
 * One conversation: the thread on the left (newest at the bottom, older ones
 * folded), the contact and the details on the right, the reply form below
 * the thread. Status and snooze sit in the header, as the actions of the
 * page.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, router } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    CardPanel,
    CommandPaletteItem,
    ConfirmationModal,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    DropdownSeparator,
    Field,
    Header,
    Heading,
    Input,
    Panel,
    PanelHeader,
} from '@statamic/cms/ui';

import MessageItem from '../../components/MessageItem.vue';
import ReplyComposer from '../../components/ReplyComposer.vue';
import ContactCard from '../../components/ContactCard.vue';
import HideSenderModal from '../../components/HideSenderModal.vue';
import { fullDateTime, snoozePresets, toLocalInput } from '../../support/format.js';
import { initiallyExpanded } from '../../support/mailBody.js';
import { isSnoozed, statusColor, statusLabel } from '../../support/status.js';
import { firstMessage } from '../../support/serverErrors.js';

const props = defineProps({
    conversation: { type: Object, required: true },
    mailbox: { type: Object, default: null },
    messages: { type: Array, required: true },
    contact: { type: Object, default: null },
    leadhub: { type: Boolean, default: false },
    templates: { type: Array, default: () => [] },
    ai: { type: Boolean, default: false },
    canHideDomain: { type: Boolean, default: true },
    canReply: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const state = ref({ ...props.conversation });
const contact = ref(props.contact);
const title = computed(() => state.value.subject || __('(no subject)'));
const snoozed = computed(() => isSnoozed(state.value));
const counterpartName = computed(() =>
    [...props.messages].reverse().find((m) => m.direction === 'in' && m.from_name)?.from_name ?? '',
);

// ── Thread ──────────────────────────────────────────────────────────────
const expanded = ref(initiallyExpanded(props.messages));

function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

// Many older messages fold into one line until asked for, as mail programs do.
const FOLD_AFTER = 3;
const showAll = ref(props.messages.length <= FOLD_AFTER + 1);
const hiddenCount = computed(() => (showAll.value ? 0 : props.messages.length - FOLD_AFTER));
const visible = computed(() => (showAll.value ? props.messages : props.messages.slice(-FOLD_AFTER)));

// ── Status, snooze, unread ──────────────────────────────────────────────
const busy = ref(false);

async function update(changes, success) {
    busy.value = true;
    try {
        const { data } = await axios.patch(props.urls.update, changes);
        state.value = { ...state.value, ...data.conversation };
        if (success) globalThis.Statamic?.$toast?.success?.(success);
        return true;
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
        return false;
    } finally {
        busy.value = false;
    }
}

function setStatus(status) {
    const messages = {
        open: __('Moved to open'),
        waiting: __('Marked as waiting'),
        closed: __('Marked as done'),
    };
    update({ status, snoozed_until: null }, messages[status]);
}

async function markUnread() {
    if (await update({ unread: true }, null)) router.visit(props.urls.index);
}

function snoozeUntil(date) {
    update({ snoozed_until: date.toISOString() }, __('Snoozed until :date', { date: fullDateTime(date.toISOString()) }));
}

const presets = snoozePresets();
const customOpen = ref(false);
const customValue = ref(toLocalInput(presets[0].date));
const customError = ref(null);

function applyCustom() {
    const date = new Date(customValue.value);
    if (Number.isNaN(date.getTime()) || date <= new Date()) {
        customError.value = __('Choose a time in the future.');
        return;
    }
    customError.value = null;
    customOpen.value = false;
    snoozeUntil(date);
}

// ── A first contact ("Neu") ─────────────────────────────────────────────
const isNew = computed(() => state.value.status === 'new');
const hideScope = ref(null);

async function accept() {
    busy.value = true;
    try {
        const { data } = await axios.post(props.urls.accept);
        state.value = { ...state.value, ...data.conversation };
        globalThis.Statamic?.$toast?.success?.(__('Taken over'));
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
    } finally {
        busy.value = false;
    }
}

function hidden(data) {
    router.visit(data?.redirect ?? props.urls.index);
}

// ── Contact ─────────────────────────────────────────────────────────────
function contactCreated(created) {
    contact.value = created;
    // A contact makes it relevant; the server moved it out of "Neu".
    state.value = { ...state.value, contact_id: created?.id ?? null, status: state.value.status === 'new' ? 'open' : state.value.status };
    router.reload({ only: ['contact'], onSuccess: (page) => { contact.value = page.props.contact; } });
}

async function unlinkContact() {
    if (await update({ contact_id: null }, __('Contact unlinked'))) contact.value = null;
}

// ── Reply ───────────────────────────────────────────────────────────────
const composer = ref(null);

function reloadThread() {
    router.reload({
        only: ['messages', 'conversation'],
        onSuccess: (page) => {
            state.value = { ...page.props.conversation };
            expanded.value = initiallyExpanded(page.props.messages);
        },
    });
}
</script>

<template>
    <Head :title="[title, __('Postfach')]" />

    <div class="max-w-page mx-auto" data-inbox-conversation>
        <Header :title="title" icon="mail-inbox-content">
            <template v-if="canReply">
                <Dropdown>
                    <DropdownMenu>
                        <DropdownItem icon="mail" :text="__('Mark as unread')" data-inbox-mark-unread @click="markUnread" />
                        <DropdownItem v-if="state.status !== 'waiting'" icon="time-clock" :text="__('Mark as waiting')" @click="setStatus('waiting')" />
                        <DropdownItem v-if="state.status !== 'open' && !isNew" icon="mail-inbox-content" :text="__('Move to open')" @click="setStatus('open')" />
                        <DropdownItem v-if="snoozed" icon="alert-alarm-bell" :text="__('End snooze')" @click="update({ snoozed_until: null }, __('Snooze ended'))" />
                        <template v-if="contact">
                            <DropdownSeparator />
                            <DropdownItem icon="x" :text="__('Unlink contact')" @click="unlinkContact" />
                        </template>
                    </DropdownMenu>
                </Dropdown>

                <Dropdown align="end">
                    <template #trigger>
                        <Button icon="time-clock" :text="__('Snooze conversation')" :disabled="busy" data-inbox-snooze />
                    </template>
                    <DropdownMenu>
                        <DropdownItem
                            v-for="preset in presets"
                            :key="preset.key"
                            :text="preset.label"
                            @click="snoozeUntil(preset.date)"
                        />
                        <DropdownSeparator />
                        <DropdownItem icon="calendar" :text="__('Pick a date…')" @click="customOpen = true" />
                    </DropdownMenu>
                </Dropdown>

                <CommandPaletteItem
                    v-if="isNew"
                    category="Actions"
                    :text="__('Accept')"
                    icon="checkmark"
                    :action="accept"
                    prioritize
                    v-slot="{ text }"
                >
                    <Button variant="primary" icon="checkmark" :text="text" :loading="busy" data-inbox-accept @click="accept" />
                </CommandPaletteItem>
                <CommandPaletteItem
                    v-else-if="state.status !== 'closed'"
                    category="Actions"
                    :text="__('Mark as done')"
                    icon="checkmark"
                    :action="() => setStatus('closed')"
                    prioritize
                    v-slot="{ text }"
                >
                    <Button variant="primary" icon="checkmark" :text="text" :loading="busy" data-inbox-done @click="setStatus('closed')" />
                </CommandPaletteItem>
                <CommandPaletteItem
                    v-else
                    category="Actions"
                    :text="__('Reopen')"
                    icon="mail-inbox-content"
                    :action="() => setStatus('open')"
                    prioritize
                    v-slot="{ text }"
                >
                    <Button variant="primary" :text="text" :loading="busy" data-inbox-reopen @click="setStatus('open')" />
                </CommandPaletteItem>
            </template>
        </Header>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="min-w-0 space-y-6">
                <Alert v-if="isNew" variant="default" icon="info" data-inbox-first-contact>
                    <Heading :text="__('A first contact')" />
                    <p class="mt-1 text-sm">
                        {{ __('You have not written to :address before, and the address is not a contact. Take the conversation over, create a contact, or hide the sender.', { address: state.counterpart_email }) }}
                    </p>
                    <div v-if="canReply" class="mt-3 flex flex-wrap gap-2">
                        <Button size="sm" icon="checkmark" :text="__('Accept')" :disabled="busy" @click="accept" />
                        <Button size="sm" icon="eye-closed" :text="__('Hide sender')" data-inbox-hide-sender @click="hideScope = 'sender'" />
                        <Button v-if="canHideDomain" size="sm" icon="eye-closed" :text="__('Hide domain')" data-inbox-hide-domain @click="hideScope = 'domain'" />
                    </div>
                </Alert>

                <Panel data-inbox-thread>
                    <PanelHeader class="flex items-center justify-between gap-2">
                        <Heading :text="__('Conversation')" />
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ messages.length === 1 ? __('One message') : __(':count messages', { count: messages.length }) }}</span>
                    </PanelHeader>
                    <div class="space-y-1.5">
                        <button
                            v-if="hiddenCount > 0"
                            type="button"
                            class="w-full cursor-pointer rounded-lg py-2 text-center text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                            data-inbox-show-older
                            @click="showAll = true"
                        >
                            {{ __('Show :count older messages', { count: hiddenCount }) }}
                        </button>
                        <MessageItem
                            v-for="message in visible"
                            :key="message.id"
                            :message="message"
                            :expanded="expanded.has(message.id)"
                            :can-reply="canReply"
                            @toggle="toggle(message.id)"
                            @reuse="composer?.setText($event)"
                        />
                    </div>
                </Panel>

                <ReplyComposer
                    v-if="canReply"
                    ref="composer"
                    :recipient="state.counterpart_email"
                    :urls="urls"
                    :templates="templates"
                    :ai="ai"
                    @sent="reloadThread"
                    @failed="reloadThread"
                />
            </div>

            <aside class="min-w-0 space-y-6">
                <ContactCard
                    :contact="contact"
                    :email="state.counterpart_email"
                    :name="counterpartName"
                    :leadhub="leadhub"
                    :can-reply="canReply"
                    :create-url="urls.contact"
                    @created="contactCreated"
                />

                <CardPanel :heading="__('Details')" data-inbox-details>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                            <dd class="mt-1 flex flex-wrap gap-1.5">
                                <Badge pill :color="statusColor(state.status)" :text="statusLabel(state.status)" data-inbox-status />
                                <Badge v-if="snoozed" pill color="purple" icon="time-clock" :text="__('Snoozed')" />
                            </dd>
                        </div>
                        <div v-if="snoozed">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Snoozed until') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ fullDateTime(state.snoozed_until) }}</dd>
                        </div>
                        <div v-if="mailbox">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Mailbox') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ mailbox.name }}</dd>
                            <dd class="text-gray-600 break-all dark:text-gray-400">{{ mailbox.email }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Last message') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ fullDateTime(state.last_message_at) }}</dd>
                        </div>
                    </dl>
                </CardPanel>
            </aside>
        </div>
    </div>

    <HideSenderModal
        :open="hideScope !== null"
        :scope="hideScope ?? 'sender'"
        :address="state.counterpart_email"
        :is-gmail="mailbox?.is_gmail ?? false"
        :url="urls.block"
        @update:open="!$event && (hideScope = null)"
        @hidden="hidden"
    />

    <ConfirmationModal
        :open="customOpen"
        :title="__('Snooze until')"
        :button-text="__('Snooze conversation')"
        @update:open="customOpen = $event"
        @confirm="applyCustom"
        @cancel="customOpen = false"
    >
        <Field id="inbox-snooze-until" :label="__('Date and time')" :error="customError"
            :instructions="__('The conversation leaves its list until then and shows up there again afterwards.')">
            <Input id="inbox-snooze-until" v-model="customValue" type="datetime-local" />
        </Field>
    </ConfirmationModal>
</template>
