<script setup>
/**
 * "Absender ausblenden" / "Domain ausblenden", with what must be said before
 * it happens: how many conversations are deleted here (asked of the server
 * when the dialog opens), that answered ones and contacts stay, and that the
 * mails stay in the mailbox. Used from the list and from the conversation.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { ConfirmationModal } from '@statamic/cms/ui';

import { firstMessage } from '../support/serverErrors.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    scope: { type: String, default: 'sender' },
    address: { type: String, default: '' },
    isGmail: { type: Boolean, default: false },
    url: { type: String, default: '' },
});

const emit = defineEmits(['update:open', 'hidden']);
const busy = ref(false);
const count = ref(null);

const domain = computed(() => props.address.split('@')[1] ?? '');
const where = computed(() => (props.isGmail ? 'Gmail' : __('your mailbox')));

const title = computed(() => (props.scope === 'domain' ? __('Hide domain?') : __('Hide sender?')));
const button = computed(() => (props.scope === 'domain' ? __('Hide domain') : __('Hide sender')));
const what = computed(() =>
    props.scope === 'domain'
        ? __('New mail from :domain no longer shows up here.', { domain: domain.value })
        : __('New mail from :address no longer shows up here.', { address: props.address }),
);
const deleted = computed(() => {
    if (count.value === null) return __('Counting the conversations that would be deleted…');
    if (count.value === 0) return __('No conversation is deleted.');
    if (count.value === 1) return __('One first contact is deleted in Statamic, attachments included.');

    return __(':count first contacts are deleted in Statamic, attachments included.', { count: count.value });
});

// How many would go: asked when the dialog opens, nothing changes yet.
watch(
    () => [props.open, props.scope, props.url],
    async ([open]) => {
        if (!open || !props.url) return;
        count.value = null;
        try {
            const { data } = await axios.post(props.url, { scope: props.scope, preview: true });
            count.value = data?.count ?? 0;
        } catch (e) {
            emit('update:open', false);
            globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
        }
    },
    { immediate: true },
);

async function confirm() {
    busy.value = true;
    try {
        const { data } = await axios.post(props.url, { scope: props.scope });
        globalThis.Statamic?.$toast?.success?.(props.scope === 'domain' ? __('Domain hidden') : __('Sender hidden'));
        emit('update:open', false);
        emit('hidden', data);
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ConfirmationModal
        :open="open"
        :title="title"
        :button-text="button"
        :busy="busy || count === null"
        danger
        @update:open="emit('update:open', $event)"
        @confirm="confirm"
        @cancel="emit('update:open', false)"
    >
        <div class="space-y-3" data-inbox-hide-dialog :data-inbox-hide-scope="scope">
            <p>{{ what }}</p>
            <p class="font-medium" data-inbox-hide-count>{{ deleted }}</p>
            <p>{{ __('Conversations you answered, contacts and conversations you took over stay, and their senders keep coming through.') }}</p>
            <p>{{ __('In :where the mails stay as they are.', { where }) }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('To undo it, remove the rule in the mailbox settings. Deleted conversations come back only with a later import.') }}
            </p>
        </div>
    </ConfirmationModal>
</template>
