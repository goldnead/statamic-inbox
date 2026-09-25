<script setup>
/**
 * One message in the thread: folded to a single line, or open with its
 * header, body, attachments and whatever went wrong sending it.
 */
import { computed, ref } from 'vue';
import { Alert, Badge, Button, Card, Description, Icon } from '@statamic/cms/ui';
import MessageFrame from './MessageFrame.vue';
import { addressLine, fileSize, fullDateTime, listTime, senderName } from '../support/format.js';
import { listedAttachments, splitText } from '../support/mailBody.js';

const props = defineProps({
    message: { type: Object, required: true },
    expanded: { type: Boolean, default: false },
    canReply: { type: Boolean, default: false },
});

defineEmits(['toggle', 'reuse']);

const outgoing = computed(() => props.message.direction === 'out');
const name = computed(() => (outgoing.value ? __('You') : senderName(props.message)));
const text = computed(() => splitText(props.message.text, props.message.body_stripped));
const snippet = computed(() => (props.message.body_stripped || props.message.text || '').replace(/\s+/g, ' ').trim());
const attachments = computed(() => listedAttachments(props.message));

const showQuoted = ref(false);
const htmlHasQuote = ref(false);
const loadRemote = ref(false);

const hasQuote = computed(() => (props.message.html_sanitized ? htmlHasQuote.value : text.value.hasQuote));
</script>

<template>
    <Card
        :data-inbox-message="message.id"
        :data-expanded="expanded ? 'true' : 'false'"
    >
        <!-- Folded: one line, the whole row opens it. -->
        <button
            v-if="!expanded"
            type="button"
            class="flex w-full cursor-pointer items-center gap-3 text-start"
            @click="$emit('toggle')"
        >
            <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ name }}</span>
            <span class="min-w-0 flex-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ snippet }}</span>
            <Icon v-if="message.attachments?.length" name="mail-send-email-attachment-document" class="size-3.5 shrink-0 text-gray-400" />
            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" :title="fullDateTime(message.sent_at)">{{ listTime(message.sent_at) }}</span>
        </button>

        <template v-else>
            <button
                type="button"
                class="flex w-full cursor-pointer items-start gap-3 pb-3 text-start"
                @click="$emit('toggle')"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ name }}</span>
                        <span v-if="!outgoing && message.from_name" class="text-sm text-gray-500 dark:text-gray-400">{{ message.from_email }}</span>
                        <Badge v-if="outgoing && !message.send_error" pill color="green" :text="__('Sent')" />
                        <Badge v-if="message.send_error" pill color="red" :text="__('Not sent')" />
                    </div>
                    <div v-if="message.to?.length" class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ __('To') }}: {{ addressLine(message.to) }}<template v-if="message.cc?.length">, {{ __('Cc') }}: {{ addressLine(message.cc) }}</template>
                    </div>
                </div>
                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ fullDateTime(message.sent_at) }}</span>
            </button>

            <div class="space-y-3">
                <Alert v-if="message.send_error" variant="error" data-inbox-send-error>
                    <p class="font-medium">{{ __('This reply was not sent') }}</p>
                    <p>{{ __('Server message') }}: {{ message.send_error }}</p>
                    <p class="mt-1">{{ __('The text is kept. You can put it back into the reply and send it again.') }}</p>
                    <div v-if="canReply" class="mt-3">
                        <Button size="sm" :text="__('Put into reply')" @click="$emit('reuse', message.text || '')" />
                    </div>
                </Alert>

                <Alert v-if="message.filed_error" variant="warning" data-inbox-filed-error>
                    <p class="font-medium">{{ __('Sent, but not filed in the Sent folder') }}</p>
                    <p>{{ __('The recipient has the reply. Your mail program will not show it under Sent.') }}</p>
                    <p class="mt-1 text-xs">{{ __('Server message') }}: {{ message.filed_error }}</p>
                </Alert>

                <div
                    v-if="message.has_remote_images && !loadRemote"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-900"
                    data-inbox-remote-blocked
                >
                    <Description :text="__('Images from the internet are blocked, so the sender cannot see that you opened this mail.')" />
                    <Button size="sm" icon="eye" :text="__('Load images')" @click="loadRemote = true" />
                </div>

                <MessageFrame
                    v-if="message.html_sanitized"
                    :html="message.html_sanitized"
                    :inline-images="message.inline_images || {}"
                    :load-remote="loadRemote"
                    :show-quoted="showQuoted"
                    :title="message.subject || name"
                    @quote="htmlHasQuote = $event"
                />
                <div
                    v-else
                    class="whitespace-pre-wrap break-words text-sm text-gray-900 dark:text-gray-100"
                    data-inbox-text
                >{{ showQuoted ? text.full : text.main }}</div>

                <Button
                    v-if="hasQuote"
                    size="xs"
                    variant="ghost"
                    icon="dots"
                    :text="showQuoted ? __('Hide quoted text') : __('Show quoted text')"
                    data-inbox-quote-toggle
                    @click="showQuoted = !showQuoted"
                />

                <ul v-if="attachments.length" class="flex flex-wrap gap-2 pt-1" data-inbox-attachments>
                    <li v-for="attachment in attachments" :key="attachment.id">
                        <a
                            :href="attachment.url"
                            class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-900"
                            target="_blank"
                            rel="noopener"
                        >
                            <Icon name="mail-send-email-attachment-document" class="size-4 text-gray-500" />
                            <span class="text-gray-900 dark:text-gray-100">{{ attachment.filename }}</span>
                            <span class="text-xs text-gray-500">{{ fileSize(attachment.size) }}</span>
                        </a>
                    </li>
                </ul>
            </div>
        </template>
    </Card>
</template>
