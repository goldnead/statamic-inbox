<script setup>
/**
 * The reply form. Three ways to fill it: write, pick a template, or have a
 * draft suggested. All three only fill the text field; nothing leaves the
 * site until someone clicks "Senden".
 *
 * Unsaved text registers with core's dirty state, so leaving the page asks
 * first, exactly like an unsaved entry.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import {
    Alert,
    Button,
    ConfirmationModal,
    Description,
    Field,
    Heading,
    Icon,
    Input,
    Panel,
    PanelHeader,
    Card,
    Select,
    Textarea,
    ToggleGroup,
    ToggleItem,
} from '@statamic/cms/ui';
import { errorBag, firstMessage } from '../support/serverErrors.js';
import { fileSize } from '../support/format.js';

const props = defineProps({
    recipient: { type: String, required: true },
    urls: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    ai: { type: Boolean, default: false },
});

const emit = defineEmits(['sent', 'failed']);

const text = ref('');
const files = ref([]);
const errors = ref({});
const sending = ref(false);
const problem = ref(null);

// ── Mode ────────────────────────────────────────────────────────────────
const modes = computed(() => [
    { value: 'write', label: __('Write'), icon: 'edit' },
    ...(props.templates.length ? [{ value: 'template', label: __('From template'), icon: 'file-content-list' }] : []),
    ...(props.ai ? [{ value: 'ai', label: __('AI draft'), icon: 'ai-sparks' }] : []),
]);
const mode = ref('write');

// ── Filling the text: template or AI, never over typed text unasked ────
const template = ref(null);
const instruction = ref('');
const filling = ref(false);
const pendingText = ref(null);

function offer(value) {
    if (text.value.trim() === '') {
        text.value = value;
        return;
    }
    pendingText.value = value;
}

function acceptPending() {
    text.value = pendingText.value ?? text.value;
    pendingText.value = null;
}

async function insertTemplate() {
    if (!template.value) return;
    filling.value = true;
    problem.value = null;
    try {
        const { data } = await axios.post(props.urls.template, { slug: template.value });
        offer(data.text ?? '');
    } catch (e) {
        problem.value = firstMessage(e, __('The template could not be loaded.'));
    } finally {
        filling.value = false;
    }
}

async function suggestDraft() {
    filling.value = true;
    problem.value = null;
    try {
        const { data } = await axios.post(props.urls.draft, { instruction: instruction.value || null });
        offer(data.text ?? '');
    } catch (e) {
        problem.value = firstMessage(e, __('No draft could be suggested.'));
    } finally {
        filling.value = false;
    }
}

// ── Attachments ─────────────────────────────────────────────────────────
const picker = ref(null);

function pick(event) {
    files.value = [...files.value, ...Array.from(event.target.files ?? [])];
    event.target.value = '';
}

function removeFile(index) {
    files.value = files.value.filter((_, i) => i !== index);
}

// ── Unsaved text ────────────────────────────────────────────────────────
const DIRTY = 'inbox-reply';
const isDirty = computed(() => text.value.trim() !== '' || files.value.length > 0);

watch(isDirty, (dirty) => {
    const state = globalThis.Statamic?.$dirty;
    if (!state) return;
    dirty ? state.add(DIRTY) : state.remove(DIRTY);
});

onBeforeUnmount(() => globalThis.Statamic?.$dirty?.remove?.(DIRTY));

// ── Send: only here, only on the click ──────────────────────────────────
async function send() {
    errors.value = {};
    problem.value = null;

    if (text.value.trim() === '') {
        errors.value = { text: __('Write something first.') };
        return;
    }

    sending.value = true;
    globalThis.Statamic?.$progress?.start?.('inbox-reply');

    const body = new FormData();
    body.append('text', text.value);
    files.value.forEach((file) => body.append('attachments[]', file));

    try {
        await axios.post(props.urls.reply, body);
        text.value = '';
        files.value = [];
        template.value = null;
        instruction.value = '';
        globalThis.Statamic?.$dirty?.remove?.(DIRTY);
        globalThis.Statamic?.$toast?.success?.(__('Reply sent'));
        emit('sent');
    } catch (e) {
        errors.value = errorBag(e);
        const message = firstMessage(e, __('Something went wrong'));
        // A field's own message belongs at the field; the toast says only
        // that something is wrong. A refused or failed send has no field.
        globalThis.Statamic?.$toast?.error?.(Object.keys(errors.value).length ? __('Something went wrong') : message);
        if (!Object.keys(errors.value).length) problem.value = message;
        // A failed send is stored with its error; the thread shows it.
        if (e?.response?.status === 502) emit('failed');
    } finally {
        sending.value = false;
        globalThis.Statamic?.$progress?.complete?.('inbox-reply');
    }
}

/** For "In die Antwort übernehmen" on a message that failed to send. */
function setText(value) {
    offer(value);
}

defineExpose({ setText });
</script>

<template>
    <Panel data-inbox-composer>
        <PanelHeader class="flex flex-wrap items-center justify-between gap-2">
            <Heading :text="__('Reply to :address', { address: recipient })" />
            <ToggleGroup v-if="modes.length > 1" v-model="mode" size="sm" data-inbox-modes>
                <ToggleItem v-for="m in modes" :key="m.value" :value="m.value" :label="m.label" :icon="m.icon" />
            </ToggleGroup>
        </PanelHeader>

        <Card class="space-y-4">
            <div v-if="mode === 'template'" class="flex flex-wrap items-end gap-2" data-inbox-template-row>
                <div class="min-w-48 flex-1">
                    <Select
                        v-model="template"
                        :options="templates"
                        :placeholder="__('Choose a template')"
                        data-inbox-template-select
                    />
                </div>
                <Button :text="__('Insert')" :disabled="!template" :loading="filling" @click="insertTemplate" />
            </div>

            <div v-if="mode === 'ai'" class="space-y-2" data-inbox-ai-row>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-48 flex-1">
                        <Input
                            v-model="instruction"
                            :placeholder="__('Optional: what should it say? e.g. short, friendly, offer a date')"
                            @keydown.enter.prevent="suggestDraft"
                        />
                    </div>
                    <Button icon="ai-sparks" :text="__('Suggest draft')" :loading="filling" data-inbox-suggest @click="suggestDraft" />
                </div>
                <Description :text="__('The draft only fills the text field. You read it, change it and send it yourself.')" />
            </div>

            <Alert v-if="problem" variant="error" :text="problem" data-inbox-composer-problem />

            <Field id="inbox-reply-text" :error="errors.text" :label="__('Your reply')">
                <Textarea id="inbox-reply-text" v-model="text" :rows="8" data-inbox-reply-text />
            </Field>

            <ul v-if="files.length" class="flex flex-wrap gap-2">
                <li
                    v-for="(file, index) in files"
                    :key="`${file.name}-${index}`"
                    class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700"
                >
                    <Icon name="mail-send-email-attachment-document" class="size-4 text-gray-500" />
                    <span>{{ file.name }}</span>
                    <span class="text-xs text-gray-500">{{ fileSize(file.size) }}</span>
                    <Button size="xs" variant="ghost" icon="x" icon-only :aria-label="__('Remove')" @click="removeFile(index)" />
                </li>
            </ul>
            <p v-if="errors['attachments.0'] || errors.attachments" class="text-sm text-red-600">{{ errors['attachments.0'] || errors.attachments }}</p>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <input ref="picker" type="file" multiple class="hidden" @change="pick" />
                    <Button
                        variant="ghost"
                        icon="mail-send-email-attachment-document"
                        :text="__('Attach file')"
                        @click="picker?.click()"
                    />
                </div>
                <Button variant="primary" :text="__('Send')" :loading="sending" data-inbox-send @click="send" />
            </div>
        </Card>
    </Panel>

    <ConfirmationModal
        :open="pendingText !== null"
        :title="__('Replace text?')"
        :body-text="__('There is already text in the reply. Replace it with the suggestion?')"
        :button-text="__('Replace')"
        @update:open="!$event && (pendingText = null)"
        @confirm="acceptPending"
        @cancel="pendingText = null"
    />
</template>
