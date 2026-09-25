import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { post: vi.fn(), patch: vi.fn() } }));
import axios from 'axios';

import ReplyComposer from '../../resources/js/components/ReplyComposer.vue';
import MessageFrame from '../../resources/js/components/MessageFrame.vue';
import MailboxEdit from '../../resources/js/pages/Mailboxes/Edit.vue';

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

    it('keeps the text when the send fails and says why', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 502, data: { message: 'SMTP refused' } } });
        const wrapper = mount(ReplyComposer, { props: { recipient: 'a@b.test', urls } });

        await wrapper.find('textarea').setValue('Hallo');
        await wrapper.find('[data-inbox-send]').trigger('click');
        await flushPromises();

        expect(wrapper.find('textarea').element.value).toBe('Hallo');
        expect(wrapper.find('[data-inbox-composer-problem]').attributes('data-attr-text')).toBe('SMTP refused');
        expect(wrapper.emitted('failed')).toHaveLength(1);
        expect(globalThis.Statamic.$toast.error).toHaveBeenCalledWith('SMTP refused');
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
        expect(wrapper.find('[data-inbox-test-result]').text()).toContain('Connection refused');
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
});
