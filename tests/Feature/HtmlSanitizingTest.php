<?php

/*
 * Spec test 6: HTML from a stranger is sanitised. Scripts and event handlers
 * go, remote images are flagged and not loaded (a tracking pixel would report
 * the read), and quoted replies are stripped from the body shown by default.
 */

use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Parsing\HtmlCleaner;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('removes scripts, event handlers, javascript: links and iframes', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml']]);

    $html = Message::sole()->html_sanitized;

    expect($html)->toBeString()
        ->toContain('Herbstangebote')
        ->toContain('https://shop.example/herbst')
        ->not->toContain('<script')
        ->not->toContain('document.cookie')
        ->not->toContain("alert('xss')")
        ->not->toContain('onclick')
        ->not->toContain('onload')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe');
});

it('flags remote images and does not let them load', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml']]);

    $message = Message::sole();

    expect($message->has_remote_images)->toBeTrue();

    // Neither the pixel nor the banner may sit in a src the browser would
    // fetch on render. They are only loaded on a click.
    expect($message->html_sanitized)
        ->not->toMatch('/\ssrc\s*=\s*["\']?https?:\/\/track\.shop\.example/i')
        ->not->toMatch('/\ssrc\s*=\s*["\']?https?:\/\/cdn\.shop\.example/i')
        ->not->toMatch('/\ssrcset\s*=/i');
});

it('blocks srcset on img and source, whatever src says', function (string $html) {
    $result = app(HtmlCleaner::class)->clean($html);

    expect($result['has_remote_images'])->toBeTrue()
        ->and($result['html'])->not->toContain('t.example/p.gif" 1x')
        ->not->toMatch('/\ssrcset\s*=/i');
})->with([
    'img with a cid src and a remote srcset' => '<p>Hallo</p><img src="cid:a" srcset="https://t.example/p.gif 1x">',
    'picture with a remote source' => '<picture><source srcset="https://t.example/p.gif"><img alt="x"></picture>',
    'protocol-relative srcset' => '<img srcset="//t.example/p.gif 2x">',
]);

it('flags a remote src on source elements (video, audio, picture)', function () {
    $result = app(HtmlCleaner::class)->clean('<video><source src="https://t.example/v.mp4"></video>');

    expect($result['has_remote_images'])->toBeTrue()
        ->and($result['html'])->not->toContain('https://t.example/v.mp4');
});

it('rewrites cid images so the CP can show them through the attachment route', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['09-inline-image.eml']]);

    $message = Message::sole();
    $inline = $message->attachments()->where('content_id', 'foto@anna.example')->sole();

    expect($message->html_sanitized)
        ->toContain('data-inbox-cid="foto@anna.example"')
        ->not->toContain('src="cid:')
        ->and($message->has_remote_images)->toBeFalse()
        ->and($inline->mime)->toBe('image/png')
        ->and($message->attachments()->where('filename', 'noten.pdf')->sole()->content_id)->toBeNull();

    $page = $this->actingAs(inboxCpUser(['view inbox']))
        ->get('/cp/inbox/conversations/'.$message->conversation_id);

    $page->assertOk();

    $props = $page->viewData('page')['props'];
    $attachments = collect($props['messages'][0]['attachments'])->keyBy('filename');

    expect($attachments['foto.png']['content_id'])->toBe('foto@anna.example')
        ->and($attachments['foto.png']['url'])->toContain('/cp/inbox/attachments/'.$inline->id)
        ->and($props['messages'][0]['inline_images'])->toBe([
            'foto@anna.example' => $attachments['foto.png']['url'],
        ]);
});

it('does not flag a message without remote images', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['03-gmail-reply.eml']]);

    $message = Message::sole();

    expect($message->has_remote_images)->toBeFalse()
        ->and($message->html_sanitized)->toContain('Dienstag passt super');
});

it('leaves html_sanitized empty for a plain-text message', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    expect(Message::sole()->html_sanitized)->toBeNull()
        ->and(Message::sole()->has_remote_images)->toBeFalse();
});

it('strips the quoted reply from a Gmail-style answer', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['03-gmail-reply.eml']]);

    $message = Message::sole();

    expect($message->body_stripped)->toContain('Dienstag passt super, danke!')
        ->not->toContain('schrieb Adrian Goldner')
        ->not->toContain('online geht gut')
        // The full text is kept; only the stripped body drops the quote.
        ->and($message->text)->toContain('online geht gut');
});

it('strips the quote from a reply written in a German mail client', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['02-sent-reply.eml']]);

    expect(Message::sole()->body_stripped)->toContain('Magst du nächste Woche Dienstag')
        ->not->toContain('seit zwei Jahren im Kammerchor');
});
