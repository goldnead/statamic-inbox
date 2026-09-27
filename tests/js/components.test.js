import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
import axios from 'axios';

import ReplyComposer from '../../resources/js/components/ReplyComposer.vue';
import MessageFrame from '../../resources/js/components/MessageFrame.vue';
import MailboxEdit from '../../resources/js/pages/Mailboxes/Edit.vue';
import HideSenderModal from '../../resources/js/components/HideSenderModal.vue';

const urls = { reply: '/reply', draft: '/draft', template: '/template', update: '/u', index: '/i', contact: '/c' };

beforeEach(() => {
    axios.post.mockReset();
    axios.patch.mockReset();
    globalThis.Statamic = { $toast: { success: vi.fn(), error: vi.fn() }, $dirty: { add: vi.fn(), remove: vi.fn() }, $progress: {} };
});

describe('ReplyComposer', () => {
    it('sends nothing without text', async () => {
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls } });

        await wrapper.find('[data-inbox-send]').trigger('click');

        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-stub="Field"]').attributes('data-attr-error')).toBe('Write something first.');
    });

    it('fills the field from a draft and sends only on the click', async () => {
        axios.post.mockResolvedValueOnce({ data: { text: 'Hallo Anna, gern.' } });
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls, ai: true } });

        wrapper.vm.$.setupState.mode = 'ai';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-inbox-suggest]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/draft', { instruction: null });
        expect(wrapper.find('textarea').element.value).toBe('Hallo Anna, gern.');

        axios.post.mockResolvedValueOnce({ data: {} });
        await wrapper.find('[data-inbox-send]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenLastCalledWith('/reply', expect.any(FormData));
        expect(wrapper.emitted('sent')).toHaveLength(1);
    });

    it('asks before a template replaces typed text', async () => {
        axios.post.mockResolvedValueOnce({ data: { text: 'Aus der Vorlage' } });
        const wrapper = mount(ReplyComposer, {
            props: { recipient: 'a@b.test', urls, templates: [{ value: 'termin', label: 'Termin' }] },
        });

        await wrapper.find('textarea').setValue('Schon getippt');
        wrapper.vm.$.setupState.mode = 'template';
        wrapper.vm.$.setupState.template = 'termin';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-inbox-template-row] [data-attr-text="Insert"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('textarea').element.value).toBe('Schon getippt');
        expect(wrapper.find('[data-stub="ConfirmationModal"]').exists()).toBe(true);

        await wrapper.find('[data-confirm]').trigger('click');

        expect(wrapper.find('textarea').element.value).toBe('Aus der Vorlage');
    });

    it('keeps the text when the send fails and says why, with the fix', async () => {
        const explanation = {
            code: 'auth', title: 'The app password for Coaching was refused', text: 'Renew it.',
            action: { label: 'Renew password', url: '/cp/inbox/mailboxes/1/edit?tab=account' }, detail: '535 5.7.8',
        };
        axios.post.mockRejectedValueOnce({ response: { status: 502, data: { message: explanation.title, explanation } } });
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls } });

        await wrapper.find('textarea').setValue('Hallo');
        await wrapper.find('[data-inbox-send]').trigger('click');
        await flushPromises();

        const notice = wrapper.find('[data-inbox-composer-problem]');
        expect(wrapper.find('textarea').element.value).toBe('Hallo');
        expect(notice.text()).toContain('The app password for Coaching was refused');
        expect(notice.find('[data-inbox-problem-action]').attributes('data-attr-href')).toBe('/cp/inbox/mailboxes/1/edit?tab=account');
        // The server's own words stay folded until asked for.
        expect(notice.text()).not.toContain('535 5.7.8');
        // The fix first, the server's words last, in one row.
        const buttons = notice.findAll('[data-stub="Button"]').map((b) => b.attributes('data-attr-text'));
        expect(buttons).toEqual(['Renew password', 'Server message']);
        await notice.find('[data-inbox-problem-detail-toggle]').trigger('click');
        expect(wrapper.find('[data-inbox-problem-detail]').text()).toBe('535 5.7.8');
        expect(wrapper.emitted('failed')).toHaveLength(1);
        expect(globalThis.Statamic.$toast.error).toHaveBeenCalledWith(explanation.title);
    });

    it('says in plain words when the AI key is missing', async () => {
        const explanation = { code: 'ai_access', title: 'The AI access is not set up or has expired', text: 'Ask.', action: null, detail: 'invalid x-api-key' };
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: explanation.title, explanation } } });
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls, ai: true } });

        wrapper.vm.$.setupState.mode = 'ai';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-inbox-suggest]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-inbox-composer-problem]').text()).toContain('The AI access is not set up or has expired');
    });

    const signatures = [
        { id: 'std', name: 'Standard', preview: 'Liebe Grüße\nAdrian' },
        { id: 'chor', name: 'Chor', preview: 'Herzlich, Adrian' },
    ];

    it('shows the preselected signature with its preview, and sends the one picked', async () => {
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls, ai: true, signatures, signature: 'chor' } });

        expect(wrapper.find('[data-inbox-signature-preview]').text()).toBe('Herzlich, Adrian');

        wrapper.vm.$.setupState.signatureId = 'none';
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-inbox-signature-preview]').exists()).toBe(false);

        axios.post.mockResolvedValueOnce({ data: { text: 'Hallo' } });
        wrapper.vm.$.setupState.mode = 'ai';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-inbox-suggest]').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenLastCalledWith('/draft', { instruction: null, signature: false });

        wrapper.vm.$.setupState.signatureId = 'std';
        axios.post.mockResolvedValueOnce({ data: {} });
        await wrapper.find('[data-inbox-send]').trigger('click');
        await flushPromises();

        const body = axios.post.mock.calls.at(-1)[1];
        expect(body.get('signature')).toBe('std');
        // Back to the preselected one for the next reply.
        expect(wrapper.vm.$.setupState.signatureId).toBe('chor');
    });

    it('says why the signature is preselected, and only while it is picked', async () => {
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls, signatures, signature: 'chor', signatureReason: 'Passt zum Tag Chor' } });
        const field = () => wrapper.find('[data-inbox-signature-field]');

        expect(field().attributes('data-attr-instructions')).toBe('Passt zum Tag Chor');

        wrapper.vm.$.setupState.signatureId = 'std';
        await wrapper.vm.$nextTick();
        expect(field().attributes('data-attr-instructions')).toBeUndefined();
    });

    it('shows no signature field when the mailbox has none', () => {
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls } });

        expect(wrapper.find('[data-inbox-signature-field]').exists()).toBe(false);
    });
});

describe('MessageFrame', () => {
    it('never lets the mail run scripts', () => {
        const wrapper = mount(MessageFrame, { props: { html: '<p>Hallo</p>' } });
        const sandbox = wrapper.find('iframe').attributes('sandbox');

        expect(sandbox).not.toContain('allow-scripts');
        expect(wrapper.find('iframe').attributes('srcdoc')).toContain('<p>Hallo</p>');
    });
});

describe('Mailboxes/Edit', () => {
    const mailbox = {
        name: 'Coaching', email: 'a@b.test', imap_host: 'imap.migadu.com', imap_port: 993, imap_encryption: 'ssl',
        username: 'a@b.test', smtp_host: 'smtp.migadu.com', smtp_port: 465, smtp_encryption: 'ssl', has_password: true,
    };
    const props = { mailbox, indexUrl: '/m', storeUrl: '/m', updateUrl: '/m/1', testUrl: '/m/1/test', presets: {} };

    it('shows a stored password as a placeholder, not viewable, and asks for it after a host change', async () => {
        const wrapper = mount(MailboxEdit, { props });
        const password = () => wrapper.find('[data-inbox-password]');

        expect(password().attributes('data-attr-placeholder')).toBe('Stored');
        expect(password().attributes('data-attr-viewable')).toBe('false');

        await wrapper.find('input#imap_host').setValue('imap.anders.test');

        expect(password().attributes('data-attr-placeholder')).toBe('');
        expect(globalThis.Statamic.$dirty.add).toHaveBeenCalledWith('inbox-mailbox');
    });

    it('tests the values in the form and shows IMAP and SMTP apart', async () => {
        axios.post.mockResolvedValueOnce({ data: { imap: { ok: true, error: null }, smtp: { ok: false, error: 'Connection refused' } } });
        const wrapper = mount(MailboxEdit, { props });

        await wrapper.find('input#imap_port').setValue('143');
        await wrapper.find('[data-inbox-test]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/m/1/test', expect.objectContaining({ imap_port: 143 }));
        expect(wrapper.find('[data-inbox-test-half="smtp"]').text()).toContain('Sending (SMTP): The connection failed');
        expect(wrapper.find('[data-inbox-test-half="smtp"]').text()).toContain('Receiving (IMAP) works.');
        expect(wrapper.find('[data-inbox-test-half="imap"]').exists()).toBe(false);
    });

    it('adds, orders and saves signatures with the mailbox', async () => {
        const withSignature = { ...mailbox, signatures: [{ id: 'a', name: 'Standard', body: 'Adrian', default: true, tags: [] }] };
        axios.patch.mockResolvedValueOnce({ data: { mailbox: { ...withSignature, signatures: [...withSignature.signatures, { id: 'b', name: 'Chor', body: 'A.', default: false, tags: ['Chor'] }] } } });
        const wrapper = mount(MailboxEdit, { props: { ...props, mailbox: withSignature, leadhubTags: ['Chor'] } });

        expect(wrapper.findAll('[data-inbox-signature]')).toHaveLength(1);
        await wrapper.find('[data-inbox-signature-add]').trigger('click');
        expect(wrapper.findAll('[data-inbox-signature]')).toHaveLength(2);

        const form = wrapper.vm.$.setupState.form;
        form.signatures[1].name = 'Chor';
        form.signatures[1].body = 'A.';
        form.signatures[1].tags = ['Chor'];
        await wrapper.find('[data-inbox-save]').trigger('click');
        await flushPromises();

        const sent = axios.patch.mock.calls[0][1].signatures;
        expect(sent.map((s) => [s.id, s.name, s.default])).toEqual([['a', 'Standard', true], [null, 'Chor', false]]);
        // The new one has its id from the server now.
        expect(wrapper.vm.$.setupState.form.signatures[1].id).toBe('b');
    });

    it('inserts the name and address placeholders by button', async () => {
        const wrapper = mount(MailboxEdit, { props: { ...props, mailbox: { ...mailbox, signatures: [{ id: 'a', name: 'S', body: 'Liebe Grüße', default: true, tags: [] }] } } });
        const form = wrapper.vm.$.setupState.form;

        await wrapper.find('[data-inbox-insert-name]').trigger('click');
        expect(form.signatures[0].body).toBe('Liebe Grüße\n{{ sender.name }}');

        await wrapper.find('[data-inbox-insert-address]').trigger('click');
        expect(form.signatures[0].body).toBe('Liebe Grüße\n{{ sender.name }}\n{{ mailbox.email }}');
    });

    it('hides the tag rules without LeadHub', () => {
        const wrapper = mount(MailboxEdit, { props: { ...props, mailbox: { ...mailbox, signatures: [{ id: 'a', name: 'S', body: 'A', default: true, tags: [] }] } } });

        expect(wrapper.find('[data-inbox-signature-tags]').exists()).toBe(false);
        expect(wrapper.find('[data-inbox-signatures-empty]').exists()).toBe(false);
    });

    it('points at the password field on the page instead of linking to it', () => {
        const problem = { code: 'auth', title: 'The app password for Coaching was refused', text: 'enter it in the mailbox', action: { label: 'Renew password', url: '/x' }, detail: '535' };
        const wrapper = mount(MailboxEdit, { props: { ...props, mailbox: { ...mailbox, last_error: '535', last_error_scope: 'mailbox', problem } } });
        const notice = wrapper.find('[data-inbox-last-error]');

        expect(notice.text()).toContain('enter it below in the Password field');
        expect(notice.find('[data-inbox-problem-action]').exists()).toBe(false);
    });

    it('points at the marked fields instead of calling it a failed connection', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'x', errors: { password: ['Enter the password again.'] } } } });
        const wrapper = mount(MailboxEdit, { props });

        await wrapper.find('input#imap_host').setValue('imap.anders.test');
        await wrapper.find('[data-inbox-test]').trigger('click');
        await flushPromises();

        const result = wrapper.find('[data-inbox-test-result]');
        expect(result.find('[data-attr-text]').attributes('data-attr-text')).toBe('Please check the marked fields.');
        expect(result.text()).not.toContain('Connection');
        // The password field says it once: its error, without the instructions repeating it.
        const field = wrapper.find('[data-stub="Field"][data-attr-id="password"]');
        expect(field.attributes('data-attr-error')).toBe('Enter the password again.');
        expect(field.attributes('data-attr-instructions')).toBeUndefined();
    });

    it('puts validation errors at their fields and raises one generic toast', async () => {
        axios.patch.mockRejectedValueOnce({ response: { status: 422, data: { message: 'x', errors: { smtp_host: ['Private address.'] } } } });
        const wrapper = mount(MailboxEdit, { props });

        await wrapper.find('[data-inbox-save]').trigger('click');
        await flushPromises();

        expect(globalThis.Statamic.$toast.error).toHaveBeenCalledWith('Something went wrong');
        expect(wrapper.vm.$.setupState.activeTab).toBe('servers');
        expect(wrapper.find('[data-stub="Field"][data-attr-id="smtp_host"]').attributes('data-attr-error')).toBe('Private address.');
    });

    it('shows the filter: skipped bulk mail, the switch, the aliases and the hidden senders to remove', async () => {
        axios.delete.mockResolvedValueOnce({ data: { deleted: true } });
        const wrapper = mount(MailboxEdit, {
            props: {
                ...props,
                mailbox: { ...mailbox, skip_bulk: true, aliases: ['kontakt@b.test'] },
                skippedBulk: 12,
                rules: [{ id: 3, type: 'domain', value: 'spam.example', delete_url: '/m/1/rules/3' }],
            },
        });

        expect(wrapper.find('[data-inbox-skipped-bulk]').attributes('data-attr-text')).toBe('12 bulk mails skipped in the last 30 days.');
        expect(wrapper.find('textarea#aliases').element.value).toBe('kontakt@b.test');
        expect(wrapper.find('[data-inbox-rule="spam.example"]').exists()).toBe(true);

        await wrapper.find('[data-inbox-rule="spam.example"] [data-attr-text="Remove"]').trigger('click');
        await flushPromises();
        expect(axios.delete).not.toHaveBeenCalled();

        await wrapper.find('[data-confirm]').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith('/m/1/rules/3');
        expect(wrapper.find('[data-inbox-rule="spam.example"]').exists()).toBe(false);
        expect(wrapper.find('[data-inbox-rules-empty]').exists()).toBe(true);
    });
});

describe('HideSenderModal', () => {
    it('asks how many would go, says so, and hides only on confirm', async () => {
        axios.post
            .mockResolvedValueOnce({ data: { count: 2 } })
            .mockResolvedValueOnce({ data: { deleted: 2, redirect: '/i?tab=new' } });
        const wrapper = mount(HideSenderModal, {
            props: { open: true, scope: 'domain', address: 'info@verlag.example', isGmail: true, url: '/block' },
        });
        await flushPromises();

        const text = wrapper.find('[data-inbox-hide-dialog]').text();

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/block', { scope: 'domain', preview: true });
        expect(text).toContain('New mail from verlag.example no longer shows up here.');
        expect(wrapper.find('[data-inbox-hide-count]').text()).toBe('2 first contacts are deleted in Statamic, attachments included.');
        expect(text).toContain('Conversations you answered, contacts and conversations you took over stay');
        expect(text).toContain('In Gmail the mails stay as they are.');

        await wrapper.find('[data-confirm]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenLastCalledWith('/block', { scope: 'domain' });
        expect(wrapper.emitted('hidden')[0][0].redirect).toBe('/i?tab=new');
    });
});
