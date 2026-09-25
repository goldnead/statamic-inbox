import { describe, expect, it } from 'vitest';
import { buildSrcdoc, initiallyExpanded, listedAttachments, splitText } from '../../resources/js/support/mailBody.js';
import {
    applyPreset, detectPreset, fromMailbox, isGmail, passwordRequired, payload, tabsWithErrors, testPayload,
} from '../../resources/js/support/mailboxForm.js';
import { dismissedSignatures, dismissSignature, isSnoozed, mailboxProblems } from '../../resources/js/support/status.js';
import { listTime, snoozePresets } from '../../resources/js/support/format.js';

const gmailReply = '<div dir="ltr"><div>Dienstag passt super, danke!</div></div><br><div><div dir="ltr">Am So., 20. Sept. 2026 um 14:00 Uhr schrieb Adrian Goldner &lt;<a href="mailto:a@b.test">a@b.test</a>&gt;:<br></div><blockquote>Hallo Anna</blockquote></div>';

describe('buildSrcdoc', () => {
    it('keeps remote images parked until they are allowed', () => {
        const html = '<img data-inbox-src="https://track.example/open.gif">';

        const blocked = buildSrcdoc(html, { origin: 'https://cms.test' }).srcdoc;
        const loaded = buildSrcdoc(html, { origin: 'https://cms.test', loadRemote: true }).srcdoc;

        expect(blocked).not.toContain(' src="https://track.example');
        expect(blocked).toContain('img-src data: https://cms.test"');
        expect(blocked).not.toContain('https: http:');
        expect(loaded).toContain('src="https://track.example/open.gif"');
        expect(loaded).toContain('img-src data: https://cms.test https: http:');
    });

    it('resolves inline images through the attachment route', () => {
        const { srcdoc } = buildSrcdoc('<img data-inbox-cid="foto&#64;anna.example">', {
            inlineImages: { 'foto@anna.example': 'https://cms.test/cp/inbox/attachments/1' },
        });

        expect(srcdoc).toContain('src="https://cms.test/cp/inbox/attachments/1"');
    });

    it('finds a Gmail quote without its class and hides it until asked', () => {
        const hidden = buildSrcdoc(gmailReply);
        const shown = buildSrcdoc(gmailReply, { showQuoted: true });

        expect(hidden.hasQuote).toBe(true);
        expect(hidden.srcdoc).toContain('[data-inbox-quote]{display:none!important}');
        expect((hidden.srcdoc.match(/data-inbox-quote=""/g) ?? []).length).toBe(2);
        expect(shown.srcdoc).not.toContain('display:none!important');
    });

    it('puts a quiet placeholder where a blocked image would be', () => {
        const { srcdoc } = buildSrcdoc('<img data-inbox-src="https://cdn.example/banner.jpg" alt="Banner">');

        expect(srcdoc).toContain('src="data:image/svg+xml,');
        expect(srcdoc).toContain('data-inbox-blocked=""');
        expect(srcdoc).toContain('alt=""');
        expect(srcdoc).toContain('title="Banner"');
    });

    it('allows no scripts, ever', () => {
        expect(buildSrcdoc('<p>x</p>', { loadRemote: true }).srcdoc).toContain("default-src 'none'");
    });
});

describe('message helpers', () => {
    it('splits the written text from the quoted history', () => {
        expect(splitText('Hallo\n\n> alt', 'Hallo')).toEqual({ main: 'Hallo', full: 'Hallo\n\n> alt', hasQuote: true });
        expect(splitText('Hallo', 'Hallo').hasQuote).toBe(false);
        expect(splitText('Hallo', '').main).toBe('Hallo');
    });

    it('does not list an inline image that the mail already shows', () => {
        const message = {
            html_sanitized: '<img data-inbox-cid="foto&#64;anna.example">',
            attachments: [{ id: 1, content_id: 'foto@anna.example' }, { id: 2, content_id: null }],
        };

        expect(listedAttachments(message).map((a) => a.id)).toEqual([2]);
    });

    it('opens the newest message and every one with an error', () => {
        const ids = initiallyExpanded([{ id: 1 }, { id: 2, send_error: 'x' }, { id: 3 }, { id: 4 }]);

        expect([...ids].sort()).toEqual([2, 4]);
    });
});

describe('mailbox form', () => {
    const stored = { has_password: true, imap_host: 'imap.migadu.com', smtp_host: 'smtp.migadu.com', username: 'a@b.test', imap_port: 993, smtp_port: 465 };

    it('keeps the stored password until server, port or login change', () => {
        const form = fromMailbox(stored);

        expect(passwordRequired(form, stored, false)).toBe(false);
        expect(passwordRequired({ ...form, imap_host: 'imap.evil.test' }, stored, false)).toBe(true);
        expect(passwordRequired({ ...form, smtp_port: 587 }, stored, false)).toBe(true);
        expect(passwordRequired({ ...form, username: 'A@B.test ' }, stored, false)).toBe(false);
        expect(passwordRequired(form, stored, true)).toBe(true);
    });

    it('fills a provider and switches the Sent copy off for Gmail', () => {
        const presets = {
            google: { imap_host: 'imap.gmail.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.gmail.com', smtp_port: 587, smtp_encryption: 'tls' },
            'all-inkl': { imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl' },
        };
        const google = applyPreset({ ...fromMailbox(), email: 'me@firma.test' }, presets.google);
        const allInkl = applyPreset({ ...fromMailbox(), imap_host: 'w01.kasserver.com' }, presets['all-inkl']);

        expect(google.append_sent).toBe(false);
        expect(google.username).toBe('me@firma.test');
        expect(detectPreset(google, presets)).toBe('google');
        expect(allInkl.imap_host).toBe('w01.kasserver.com');
        expect(allInkl.append_sent).toBe(true);
        expect(isGmail('imap.googlemail.com')).toBe(true);
    });

    it('sends the password to the test only when typed', () => {
        const form = fromMailbox(stored);

        expect(testPayload(form)).not.toHaveProperty('password');
        expect(testPayload({ ...form, password: 'neu' }).password).toBe('neu');
        expect(payload({ ...form, imap_port: '993' }).imap_port).toBe(993);
    });

    it('knows which tab an error sits on', () => {
        expect([...tabsWithErrors({ password: 'x', smtp_host: 'y' })].sort()).toEqual(['account', 'servers']);
    });
});

describe('status and dates', () => {
    it('words what the fetch could not do', () => {
        const refused = { code: 'auth', title: 'The app password for Chor was refused', text: 'Renew it.', action: { label: 'Renew password', url: '/edit?tab=account' }, detail: '535' };
        const problems = mailboxProblems(
            [
                { id: 1, name: 'Chor', last_error: '535', last_error_scope: 'mailbox', problem: refused },
                { id: 2, name: 'Coaching', sent_folder: 'Gesendet', last_error: 'x', last_error_scope: 'folder', folder_problems: { Gesendet: { code: 'folder', title: 't', text: 'Rename it.', detail: 'NONEXISTENT' } } },
            ],
            [{ mailbox_id: 2, folder: 'INBOX', count: 2, latest: 9 }],
        );

        expect(problems.map((p) => [p.variant, p.problem.title])).toEqual([
            ['error', 'The app password for Chor was refused'],
            ['warning', 'The Sent folder of Coaching cannot be read'],
            ['warning', '2 messages in the inbox of Coaching could not be read'],
        ]);
        expect(problems[0].problem.action.url).toBe('/edit?tab=account');
        expect(problems[2].dismissible).toBe(true);
        expect(problems[2].signature).toBe('2:INBOX:9');
    });

    it('remembers a hidden notice until something new happens', () => {
        dismissSignature('2:INBOX:9');

        expect(dismissedSignatures().has('2:INBOX:9')).toBe(true);
        expect(dismissedSignatures().has('2:INBOX:10')).toBe(false);
    });

    it('snoozes to the coming Monday, not today', () => {
        const monday = new Date(2026, 8, 28, 9, 0);
        const presets = snoozePresets(monday);

        expect(presets.find((p) => p.key === 'monday').date.getDate()).toBe(5);
        expect(presets.find((p) => p.key === 'tomorrow').date.getHours()).toBe(8);
        expect(isSnoozed({ snoozed_until: new Date(2026, 8, 29).toISOString() }, monday)).toBe(true);
    });

    it('lists today by time and older days by date', () => {
        const now = new Date(2026, 8, 25, 12, 0);

        expect(listTime(new Date(2026, 8, 25, 9, 5).toISOString(), now, 'de')).toBe('09:05');
        expect(listTime(new Date(2025, 0, 2).toISOString(), now, 'de')).toBe('02.01.2025');
    });
});
