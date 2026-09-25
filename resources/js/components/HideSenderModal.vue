<script setup>
/**
 * "Absender ausblenden" / "Domain ausblenden", with the one thing that must
 * be said before it happens: the conversations are deleted here, the mails
 * stay in the mailbox. Used from the list and from the conversation.
 */
import { computed, ref } from 'vue';
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

const domain = computed(() => props.address.split('@')[1] ?? '');
const where = computed(() => (props.isGmail ? 'Gmail' : __('your mailbox')));

const title = computed(() => (props.scope === 'domain' ? __('Hide domain?') : __('Hide sender?')));
const button = computed(() => (props.scope === 'domain' ? __('Hide domain') : __('Hide sender')));
const what = computed(() =>
    props.scope === 'domain'
        ? __('Nobody from :domain shows up here any more. Their conversations are deleted in Statamic, attachments included.', { domain: domain.value })
        : __(':address no longer shows up here. The conversations with this address are deleted in Statamic, attachments included.', { address: props.address }),
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
        :busy="busy"
        danger
        @update:open="emit('update:open', $event)"
        @confirm="confirm"
        @cancel="emit('update:open', false)"
    >
        <div class="space-y-3" data-inbox-hide-dialog :data-inbox-hide-scope="scope">
            <p>{{ what }}</p>
            <p>{{ __('In :where the mails stay as they are.', { where }) }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('To undo it, remove the rule in the mailbox settings. Deleted conversations come back only with a later import.') }}
            </p>
        </div>
    </ConfirmationModal>
</template>
