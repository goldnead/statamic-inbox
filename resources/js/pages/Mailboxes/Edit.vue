<script setup>
/**
 * Connect or edit one mailbox. Laid out like the Connections screen of
 * statamic-automations: tabs, a test button next to Save, write-only secrets.
 *
 * The password is never sent to the browser. A stored one shows as the
 * placeholder "Gespeichert"; left empty it is kept. It is asked for again
 * when server, port or login change, because the stored password would
 * otherwise go to a server it was not meant for.
 *
 * "Verbindung testen" tests what the form says now, saved or not, IMAP and
 * SMTP separately, and stores nothing.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    CommandPaletteItem,
    Description,
    Field,
    Header,
    Input,
    Panel,
    Select,
    Switch,
    TabContent,
    TabList,
    Tabs,
    TabTrigger,
} from '@statamic/cms/ui';

import { errorBag, errorMessages, firstMessage } from '../../support/serverErrors.js';
import {
    applyPreset,
    CUSTOM,
    detectPreset,
    fromMailbox,
    isGmail,
    passwordRequired,
    payload,
    TAB_FIELDS,
    tabsWithErrors as errorTabs,
    testPayload,
} from '../../support/mailboxForm.js';
import { fullDateTime } from '../../support/format.js';

const props = defineProps({
    mailbox: { type: Object, required: true },
    isNew: { type: Boolean, default: false },
    presets: { type: Object, default: () => ({}) },
    importDays: { type: Number, default: 90 },
    indexUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    updateUrl: { type: String, default: null },
    testUrl: { type: String, required: true },
});

const stored = ref({ ...props.mailbox });
const form = ref(fromMailbox(props.mailbox));
const errors = ref({});
const saving = ref(false);
const title = computed(() => (props.isNew ? __('Connect mailbox') : stored.value.name));

// ── Provider ────────────────────────────────────────────────────────────
const provider = ref(detectPreset(form.value, props.presets));
const providerOptions = computed(() => [
    ...Object.entries(props.presets).map(([value, preset]) => ({ value, label: preset.label })),
    { value: CUSTOM, label: __('Other provider') },
]);
const preset = computed(() => props.presets[provider.value] ?? null);

function pickProvider(value) {
    provider.value = value;
    if (value !== CUSTOM) form.value = applyPreset(form.value, props.presets[value]);
}

const gmail = computed(() => isGmail(form.value.imap_host) || isGmail(form.value.smtp_host));

// ── Password ────────────────────────────────────────────────────────────
const needsPassword = computed(() => passwordRequired(form.value, stored.value, props.isNew));
const keepsPassword = computed(() => !props.isNew && stored.value.has_password && !needsPassword.value);

const passwordInstructions = computed(() => {
    if (!props.isNew && stored.value.has_password && needsPassword.value) {
        return __('Server, port or login changed. Enter the password again so it is not sent to a server it was not meant for.');
    }
    if (keepsPassword.value) return __('Stored and not shown. Leave empty to keep it, type to replace it.');
    return __('Use an app password, not the password you log in with. Most providers require one for mail programs.');
});

// ── Encryption options: IMAP and SMTP name them differently ─────────────
const imapEncryptions = computed(() => [
    { value: 'ssl', label: __('SSL/TLS (usually port 993)') },
    ...(form.value.imap_encryption === 'tls' ? [{ value: 'tls', label: __('SSL/TLS') }] : []),
    { value: 'starttls', label: __('STARTTLS (usually port 143)') },
    { value: 'none', label: __('No encryption') },
]);
const smtpEncryptions = computed(() => [
    { value: 'ssl', label: __('SSL/TLS (usually port 465)') },
    { value: 'tls', label: __('STARTTLS (usually port 587)') },
    ...(form.value.smtp_encryption === 'starttls' ? [{ value: 'starttls', label: __('STARTTLS') }] : []),
    { value: 'none', label: __('No encryption') },
]);

// ── Unsaved changes: core's dirty state owns the prompts ────────────────
const DIRTY = 'inbox-mailbox';
let snapshot = JSON.stringify(form.value);
const isDirty = computed(() => JSON.stringify(form.value) !== snapshot);

watch(isDirty, (dirty) => {
    const state = globalThis.Statamic?.$dirty;
    if (!state) return;
    dirty ? state.add(DIRTY) : state.remove(DIRTY);
});

function markClean() {
    snapshot = JSON.stringify(form.value);
    form.value = { ...form.value };
    globalThis.Statamic?.$dirty?.remove?.(DIRTY);
}

onBeforeUnmount(() => globalThis.Statamic?.$dirty?.remove?.(DIRTY));

// ── Tabs ────────────────────────────────────────────────────────────────
const activeTab = ref('account');
const tabsWithErrors = computed(() => errorTabs(errors.value));

const shownKeys = Object.values(TAB_FIELDS).flat();
const otherErrors = computed(() =>
    Object.entries(errors.value).filter(([key]) => !shownKeys.includes(key)).map(([, message]) => message),
);

// ── Save ────────────────────────────────────────────────────────────────
async function save() {
    saving.value = true;
    globalThis.Statamic?.$progress?.start?.('inbox-mailbox-save');
    try {
        if (props.isNew) {
            const { data } = await axios.post(props.storeUrl, payload(form.value));
            globalThis.Statamic?.$toast?.success?.(__('Mailbox connected'));
            markClean();
            // Core clears its leave-page prompt in a watcher on the next tick.
            await nextTick();
            router.visit(data.mailbox.edit_url);
            return;
        }

        const { data } = await axios.patch(props.updateUrl, payload(form.value));
        stored.value = data.mailbox;
        form.value = { ...form.value, password: '', sent_folder: data.mailbox.sent_folder ?? '' };
        errors.value = {};
        markClean();
        globalThis.Statamic?.$toast?.success?.(__('Saved'));
    } catch (e) {
        errors.value = errorBag(e);
        const first = Object.keys(TAB_FIELDS).find((t) => tabsWithErrors.value.has(t));
        if (first) activeTab.value = first;
        // The fields carry their own messages; the toast only says that.
        globalThis.Statamic?.$toast?.error?.(
            Object.keys(errors.value).length ? __('Something went wrong') : firstMessage(e, __('Something went wrong')),
        );
    } finally {
        saving.value = false;
        globalThis.Statamic?.$progress?.complete?.('inbox-mailbox-save');
    }
}

// ── Test ────────────────────────────────────────────────────────────────
const testing = ref(false);
const testResult = ref(null);

async function runTest() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(props.testUrl, testPayload(form.value));
        testResult.value = data;
    } catch (e) {
        const bag = errorBag(e);
        if (Object.keys(bag).length) {
            errors.value = { ...errors.value, ...bag };
            const first = Object.keys(TAB_FIELDS).find((t) => tabsWithErrors.value.has(t));
            if (first) activeTab.value = first;
        }
        // A field's message stands at the field; the alert does not repeat it.
        testResult.value = {
            failed: Object.keys(bag).length ? __('Check the marked fields first.') : (errorMessages(e)[0] ?? __('The test could not run.')),
        };
    } finally {
        testing.value = false;
    }
}

// A mailbox that cannot be reached at all is an error; a folder or a
// message the fetch could not read is a warning.
const lastErrorVariant = computed(() => (stored.value.last_error_scope === 'mailbox' ? 'error' : 'warning'));

const testOk = computed(() => testResult.value?.imap?.ok && testResult.value?.smtp?.ok);
</script>

<template>
    <Head :title="[title, __('Mailboxes'), __('Postfach')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper data-inbox-mailbox-form>
        <Header :title="title" icon="mail-settings">
            <Button :loading="testing" :text="__('Test connection')" data-inbox-test @click="runTest" />
            <CommandPaletteItem
                category="Actions"
                :text="isNew ? __('Connect mailbox') : __('Save')"
                icon="save"
                :action="save"
                prioritize
                v-slot="{ text }"
            >
                <Button variant="primary" :text="text" :loading="saving" data-inbox-save @click="save" />
            </CommandPaletteItem>
        </Header>

        <Alert v-if="otherErrors.length" variant="error" class="mb-4">
            <ul class="list-disc list-inside space-y-0.5">
                <li v-for="(message, i) in otherErrors" :key="i">{{ message }}</li>
            </ul>
        </Alert>

        <Alert
            v-if="testResult"
            :variant="testOk ? 'success' : 'error'"
            class="mb-4"
            data-inbox-test-result
        >
            <p class="font-medium">{{ testOk ? __('Connection works') : __('Connection failed') }}</p>
            <p v-if="testResult.failed">{{ testResult.failed }}</p>
            <ul v-else class="space-y-1">
                <li>
                    <strong>{{ __('Receiving (IMAP)') }}:</strong>
                    {{ testResult.imap.ok ? __('works') : testResult.imap.error }}
                </li>
                <li>
                    <strong>{{ __('Sending (SMTP)') }}:</strong>
                    {{ testResult.smtp.ok ? __('works') : testResult.smtp.error }}
                </li>
            </ul>
        </Alert>

        <Alert
            v-if="!isNew && stored.last_error"
            :variant="lastErrorVariant"
            class="mb-4"
            data-inbox-last-error
        >
            <p class="font-medium">{{ stored.last_error_scope === 'mailbox' ? __('The last fetch failed') : __('The last fetch had problems') }}</p>
            <p class="break-words">{{ __('Server message') }}: {{ stored.last_error }}</p>
            <p v-if="stored.last_fetched_at" class="mt-1 text-xs">{{ fullDateTime(stored.last_fetched_at) }}</p>
        </Alert>

        <Tabs v-model="activeTab">
            <TabList>
                <TabTrigger name="account">
                    {{ __('Account') }}
                    <Badge v-if="tabsWithErrors.has('account')" color="red" pill class="ms-1.5" text="!" :aria-label="__('This tab has errors')" />
                </TabTrigger>
                <TabTrigger name="servers">
                    {{ __('Servers') }}
                    <Badge v-if="tabsWithErrors.has('servers')" color="red" pill class="ms-1.5" text="!" :aria-label="__('This tab has errors')" />
                </TabTrigger>
                <TabTrigger name="folders">
                    {{ __('Folders and import') }}
                    <Badge v-if="tabsWithErrors.has('folders')" color="red" pill class="ms-1.5" text="!" :aria-label="__('This tab has errors')" />
                </TabTrigger>
            </TabList>

            <TabContent name="account">
                <Panel class="mt-4">
                    <Card class="space-y-6">
                        <Field
                            id="provider"
                            :label="__('Mail provider')"
                            :instructions="__('Fills in the servers. Choose Other provider to enter them yourself.')"
                        >
                            <Select id="provider" :model-value="provider" :options="providerOptions" data-inbox-provider @update:model-value="pickProvider" />
                        </Field>

                        <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                            <Field id="name" :label="__('Name')" required :error="errors.name"
                                :instructions="__('How the mailbox is called in the list, e.g. Coaching.')">
                                <Input id="name" v-model="form.name" :focus="isNew" />
                            </Field>
                            <Field id="email" :label="__('Email address')" required :error="errors.email"
                                :instructions="__('Replies go out from this address.')">
                                <Input id="email" v-model="form.email" type="email" />
                            </Field>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                            <Field id="username" :label="__('Username')" required :error="errors.username"
                                :instructions="__('Usually the email address.')">
                                <Input id="username" v-model="form.username" autocomplete="off" />
                            </Field>
                            <Field
                                id="password"
                                :label="__('Password')"
                                :required="needsPassword"
                                :error="errors.password"
                                :instructions="passwordInstructions"
                            >
                                <Input
                                    id="password"
                                    v-model="form.password"
                                    type="password"
                                    :placeholder="keepsPassword ? __('Stored') : ''"
                                    :viewable="!keepsPassword && form.password !== ''"
                                    autocomplete="new-password"
                                    data-inbox-password
                                />
                            </Field>
                        </div>

                        <Description v-if="preset?.help" data-inbox-app-password-help>
                            {{ __('How to create an app password at :provider:', { provider: preset.label }) }}
                            <a :href="preset.help" target="_blank" rel="noopener" class="underline">{{ __('instructions') }}</a>
                        </Description>
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="servers">
                <Panel class="mt-4" :heading="__('Receiving (IMAP)')">
                    <Card class="space-y-6">
                        <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-6 *:min-w-0">
                            <Field id="imap_host" :label="__('Server')" required :error="errors.imap_host">
                                <Input id="imap_host" v-model="form.imap_host" placeholder="imap.example.com" class="font-mono" />
                            </Field>
                            <Field id="imap_port" :label="__('Port')" required :error="errors.imap_port">
                                <Input id="imap_port" v-model="form.imap_port" type="number" min="1" max="65535" />
                            </Field>
                        </div>
                        <Field id="imap_encryption" :label="__('Encryption')" required :error="errors.imap_encryption">
                            <Select id="imap_encryption" v-model="form.imap_encryption" :options="imapEncryptions" />
                        </Field>
                    </Card>
                </Panel>

                <Panel class="mt-6" :heading="__('Sending (SMTP)')">
                    <Card class="space-y-6">
                        <div class="grid sm:grid-cols-[minmax(0,1fr)_8rem] gap-6 *:min-w-0">
                            <Field id="smtp_host" :label="__('Server')" required :error="errors.smtp_host">
                                <Input id="smtp_host" v-model="form.smtp_host" placeholder="smtp.example.com" class="font-mono" />
                            </Field>
                            <Field id="smtp_port" :label="__('Port')" required :error="errors.smtp_port">
                                <Input id="smtp_port" v-model="form.smtp_port" type="number" min="1" max="65535" />
                            </Field>
                        </div>
                        <Field id="smtp_encryption" :label="__('Encryption')" required :error="errors.smtp_encryption">
                            <Select id="smtp_encryption" v-model="form.smtp_encryption" :options="smtpEncryptions" />
                        </Field>
                        <Description :text="__('Private and local addresses are refused, so this form cannot reach into the network the site runs in.')" />
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="folders">
                <Panel class="mt-4">
                    <Card class="space-y-6">
                        <div class="grid sm:grid-cols-2 gap-6 *:min-w-0">
                            <Field id="inbox_folder" :label="__('Inbox folder')" :error="errors.inbox_folder"
                                :instructions="__('Almost always INBOX.')">
                                <Input id="inbox_folder" v-model="form.inbox_folder" placeholder="INBOX" class="font-mono" />
                            </Field>
                            <Field id="sent_folder" :label="__('Sent folder')" :error="errors.sent_folder"
                                :instructions="__('Found automatically when saving. Change it only if your provider names it differently.')">
                                <Input id="sent_folder" v-model="form.sent_folder" :placeholder="__('Find automatically')" class="font-mono" data-inbox-sent-folder />
                            </Field>
                        </div>

                        <Field
                            id="append_sent"
                            :label="__('Put a copy of each reply into the Sent folder')"
                            :error="errors.append_sent"
                            :instructions="gmail
                                ? __('Off for Google: Gmail files mail sent through its SMTP into Sent by itself. A copy would show up there twice.')
                                : __('So your replies from here also show up under Sent in your mail program.')"
                        >
                            <Switch id="append_sent" v-model="form.append_sent" data-inbox-append-sent />
                        </Field>

                        <Field
                            id="import_since"
                            :label="__('Import mail since')"
                            :error="errors.import_since"
                            :instructions="__('The first fetch takes mail from this day on. Default: the last :days days.', { days: importDays })"
                        >
                            <Input id="import_since" v-model="form.import_since" type="date" class="max-w-56" />
                        </Field>

                        <Field
                            id="active"
                            :label="__('Fetch mail')"
                            :error="errors.active"
                            :instructions="__('Switched off, the mailbox keeps its conversations but no new mail is fetched.')"
                        >
                            <Switch id="active" v-model="form.active" />
                        </Field>
                    </Card>
                </Panel>
            </TabContent>
        </Tabs>
    </div>
</template>
