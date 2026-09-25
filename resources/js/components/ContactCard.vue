<script setup>
/**
 * Who is on the other side: the LeadHub contact with a link to it, or the
 * address and a button to make it a contact. A contact is only ever created
 * on that click, never by fetching mail.
 */
import { ref } from 'vue';
import axios from 'axios';
import { Badge, Button, CardPanel, Description } from '@statamic/cms/ui';
import { firstMessage } from '../support/serverErrors.js';

const props = defineProps({
    contact: { type: Object, default: null },
    email: { type: String, required: true },
    // The name the other side gives in its mails, while there is no contact.
    name: { type: String, default: '' },
    leadhub: { type: Boolean, default: false },
    canReply: { type: Boolean, default: false },
    createUrl: { type: String, required: true },
});

const emit = defineEmits(['created']);

const creating = ref(false);

async function create() {
    creating.value = true;
    try {
        const { data } = await axios.post(props.createUrl);
        globalThis.Statamic?.$toast?.success?.(__('Contact created'));
        emit('created', data.contact);
    } catch (e) {
        globalThis.Statamic?.$toast?.error?.(firstMessage(e, __('Something went wrong')));
    } finally {
        creating.value = false;
    }
}
</script>

<template>
    <CardPanel :heading="__('Contact')" data-inbox-contact-card>
        <div v-if="contact" class="space-y-3">
            <div>
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ contact.full_name || contact.email }}</div>
                <div class="text-sm text-gray-600 break-all dark:text-gray-400">{{ contact.email }}</div>
                <div v-if="contact.company" class="text-sm text-gray-600 dark:text-gray-400">{{ contact.company }}</div>
            </div>
            <div v-if="contact.tags?.length" class="flex flex-wrap gap-1">
                <Badge v-for="tag in contact.tags" :key="tag" pill :text="tag" />
            </div>
            <Button
                v-if="contact.url"
                :href="contact.url"
                icon="users"
                :text="__('Open contact')"
                size="sm"
                data-inbox-contact-link
            />
        </div>

        <div v-else class="space-y-3">
            <div>
                <div v-if="name" class="font-medium text-gray-900 dark:text-gray-100">{{ name }}</div>
                <div class="text-sm break-all" :class="name ? 'text-gray-600 dark:text-gray-400' : 'text-gray-900 dark:text-gray-100'">{{ email }}</div>
            </div>
            <template v-if="leadhub">
                <Description :text="__('This address is not a contact yet.')" />
                <Button
                    v-if="canReply"
                    icon="add-user"
                    size="sm"
                    :text="__('Create contact')"
                    :loading="creating"
                    data-inbox-create-contact
                    @click="create"
                />
            </template>
        </div>
    </CardPanel>
</template>
