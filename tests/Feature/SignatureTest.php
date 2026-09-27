<?php

/*
 * 0.2.2, item 4: signatures per mailbox. One is the default; a signature can
 * name LeadHub tags, and the first one (in their order) whose tag the contact
 * carries is picked instead. "Keine" leaves it out. It sits under the text
 * and above the quote, in the text part after "-- " and in the HTML part,
 * where the signature's text is escaped and only its links become links.
 */

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\StatamicInbox\Ai\DraftSuggester;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Parsing\QuoteStripper;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\Sending\Signatures;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox([
        'from_name' => 'Adrian Goldner',
        'signatures' => [
            ['id' => 'sig-default', 'name' => 'Standard', 'body' => "Liebe Grüße\n{{ sender.name }}", 'default' => true, 'tags' => []],
            ['id' => 'sig-chor', 'name' => 'Chorleitung', 'body' => "Herzlich\n{{sender.name}}, Chorleitung\n{{ mailbox.email }}", 'default' => false, 'tags' => ['Chorleitung']],
            ['id' => 'sig-kurs', 'name' => 'Kurs', 'body' => 'Dein Kursteam', 'default' => false, 'tags' => ['Kurs', 'Chorleitung']],
        ],
    ]);

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);
    $this->conversation = Conversation::sole();
});

function sentEmail($smtp): Email
{
    return $smtp->transport->messages()->last()->getOriginalMessage();
}

it('uses the default signature', function () {
    app(ReplySender::class)->send($this->conversation, 'Bis Dienstag!');

    expect(sentEmail($this->smtp)->getTextBody())->toContain("Bis Dienstag!\n\n-- \nLiebe Grüße\nAdrian Goldner");
});

it('picks the first signature whose tag the contact carries', function () {
    LeadHub::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Anna', 'tags' => ['Chorleitung']]);

    expect(app(Signatures::class)->suggest($this->mailbox->fresh(), $this->conversation->fresh())['id'])->toBe('sig-chor');

    app(ReplySender::class)->send($this->conversation->fresh(), 'Bis Dienstag!');

    expect(sentEmail($this->smtp)->getTextBody())->toContain("-- \nHerzlich\nAdrian Goldner, Chorleitung\nadrian@goldner.test");
});

it('falls back to the default when no tag matches', function () {
    LeadHub::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Anna', 'tags' => ['Sopran']]);

    expect(app(Signatures::class)->suggest($this->mailbox->fresh(), $this->conversation->fresh())['id'])->toBe('sig-default');
});

it('uses the signature picked in the form', function () {
    app(ReplySender::class)->send($this->conversation, 'Bis Dienstag!', [], 'sig-kurs');

    expect(sentEmail($this->smtp)->getTextBody())->toContain("-- \nDein Kursteam");
});

it('leaves the signature out on "Keine"', function () {
    app(ReplySender::class)->send($this->conversation, 'Bis Dienstag!', [], Signatures::NONE);

    expect(sentEmail($this->smtp)->getTextBody())->not->toContain('-- ')
        ->not->toContain('Liebe Grüße');
});

it('sends no signature when the mailbox has none', function () {
    $this->mailbox->update(['signatures' => null]);

    app(ReplySender::class)->send($this->conversation->fresh(), 'Bis Dienstag!');

    expect(sentEmail($this->smtp)->getTextBody())->not->toContain('-- ');
});

it('puts the signature under the text and above the quote, in both parts', function () {
    app(ReplySender::class)->send($this->conversation, 'Bis Dienstag!');

    $email = sentEmail($this->smtp);
    $text = $email->getTextBody();
    $html = (string) $email->getHtmlBody();

    expect(strpos($text, 'Bis Dienstag!'))->toBeLessThan(strpos($text, "-- \nLiebe Grüße"))
        ->and(strpos($text, 'Liebe Grüße'))->toBeLessThan(strpos($text, QuoteStripper::MARKER))
        ->and($html)->toContain('Bis Dienstag!')
        ->and(strpos($html, 'Bis Dienstag!'))->toBeLessThan(strpos($html, 'Liebe Grüße'))
        ->and(strpos($html, 'Liebe Grüße'))->toBeLessThan(strpos($html, '<blockquote'));
});

it('escapes the HTML part and links only the links', function () {
    $this->mailbox->update(['signatures' => [[
        'id' => 's', 'name' => 'S', 'default' => true, 'tags' => [],
        'body' => "<b>Adrian</b> & Team <script>alert(1)</script>\nhttps://adriangoldner.com/kontakt?a=1&b=2",
    ]]]);

    app(ReplySender::class)->send($this->conversation->fresh(), 'Ich <3 dich & den Chor');

    $html = (string) sentEmail($this->smtp)->getHtmlBody();

    expect($html)->not->toContain('<b>')
        ->not->toContain('<script>')
        ->toContain('&lt;b&gt;Adrian&lt;/b&gt; &amp; Team')
        ->toContain('Ich &lt;3 dich &amp; den Chor')
        ->toContain('<a href="https://adriangoldner.com/kontakt?a=1&amp;b=2">https://adriangoldner.com/kontakt?a=1&amp;b=2</a>');
});

it('fills only its two placeholders', function () {
    $signatures = app(Signatures::class);
    $mailbox = new Mailbox(['email' => 'info@goldner.test', 'from_name' => 'Adrian Goldner']);

    expect($signatures->render(['body' => '{{ sender.name }} · {{mailbox.email}} · {{ contact.name }}'], $mailbox))
        ->toBe('Adrian Goldner · info@goldner.test · {{ contact.name }}');
});

it('hands the reply form the signatures, previews and the preselected one', function () {
    $response = $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->get(cp_route('inbox.conversations.show', $this->conversation->id));
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['signature'])->toBe('sig-default')
        ->and($props['signatureReason'])->toBe('Default')
        ->and(collect($props['signatures'])->pluck('id')->all())->toBe(['sig-default', 'sig-chor', 'sig-kurs'])
        ->and($props['signatures'][0]['preview'])->toBe("Liebe Grüße\nAdrian Goldner");
});

it('says why a signature is preselected: the tag that matched', function () {
    LeadHub::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Anna', 'tags' => ['Chorleitung']]);

    $response = $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->get(cp_route('inbox.conversations.show', $this->conversation->id));
    $props = $response->viewData('page')['props'];

    expect($props['signature'])->toBe('sig-chor')
        ->and($props['signatureReason'])->toBe('Matches the tag Chorleitung');
});

it('does not split the HTML part at a quote marker typed into the text or the signature', function () {
    $this->mailbox->update(['signatures' => [[
        'id' => 's', 'name' => 'S', 'default' => true, 'tags' => [],
        'body' => "Adrian\n".QuoteStripper::MARKER."\nSignaturende",
    ]]]);

    app(ReplySender::class)->send($this->conversation->fresh(), "Oben\n\n".QuoteStripper::MARKER."\n\nNach dem Marker");

    $html = (string) sentEmail($this->smtp)->getHtmlBody();
    $quoteAt = strpos($html, '<blockquote');

    expect(substr_count($html, '<blockquote'))->toBe(1)
        ->and(strpos($html, 'Nach dem Marker'))->toBeLessThan($quoteAt)
        ->and(strpos($html, 'Signaturende'))->toBeLessThan($quoteAt)
        // The separator paragraph is the real one, under the signature.
        ->and(substr_count($html, '<p>'))->toBe(1)
        ->and(strpos($html, 'Signaturende'))->toBeLessThan(strpos($html, '<p>'))
        ->and(substr($html, $quoteAt))->not->toContain('Nach dem Marker')
        // The quote is Anna's mail, not our own text.
        ->and(substr($html, $quoteAt))->toContain('Dienstag');
});

it('sends the signature picked in the reply form', function () {
    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson(cp_route('inbox.conversations.reply', $this->conversation->id), ['text' => 'Bis bald', 'signature' => 'none'])
        ->assertCreated();

    expect(sentEmail($this->smtp)->getTextBody())->not->toContain('-- ');

    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson(cp_route('inbox.conversations.reply', $this->conversation->id), ['text' => 'Bis bald', 'signature' => 'nope'])
        ->assertUnprocessable();
});

it('saves signatures from the mailbox form, with exactly one default', function () {
    $user = inboxCpUser(['manage inbox mailboxes']);
    $mailbox = $this->mailbox;

    $this->actingAs($user)->patchJson(cp_route('inbox.mailboxes.update', $mailbox->id), [
        ...collect($mailbox->only(['name', 'email', 'username', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']))->all(),
        'signatures' => [
            ['name' => 'Kurz', 'body' => 'Adrian', 'default' => false, 'tags' => []],
            ['name' => 'Lang', 'body' => "Liebe Grüße\nAdrian", 'default' => true, 'tags' => ['Chorleitung']],
            ['name' => 'Noch eine', 'body' => 'A.', 'default' => true, 'tags' => []],
        ],
    ])->assertOk();

    $saved = $mailbox->fresh()->signatures;

    expect(array_column($saved, 'name'))->toBe(['Kurz', 'Lang', 'Noch eine'])
        ->and(array_column($saved, 'default'))->toBe([false, true, false])
        ->and($saved[0]['id'])->toBeString()->not->toBe('')
        ->and($saved[1]['tags'])->toBe(['Chorleitung']);

    $this->actingAs($user)->patchJson(cp_route('inbox.mailboxes.update', $mailbox->id), [
        ...collect($mailbox->only(['name', 'email', 'username', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']))->all(),
        'signatures' => [['name' => '', 'body' => '', 'default' => true, 'tags' => []]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['signatures.0.name', 'signatures.0.body']);
});

it('tells the AI not to sign when a signature is chosen', function () {
    config()->set('inbox.ai.api_key', 'sk-ant-test-key');
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hallo Anna']]])]);

    app(DraftSuggester::class)->suggest($this->conversation, null, signature: true);
    app(DraftSuggester::class)->suggest($this->conversation, null, signature: false);

    $systems = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]['system'])->values();

    expect($systems[0])->toContain('signature')
        ->and($systems[1])->not->toContain('signature');
});

it('asks for a draft without a closing name when the form has a signature', function () {
    config()->set('inbox.ai.api_key', 'sk-ant-test-key');
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hallo Anna']]])]);

    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson(cp_route('inbox.conversations.draft', $this->conversation->id), ['signature' => true])
        ->assertOk();

    Http::assertSent(fn (Request $request) => str_contains((string) $request['system'], 'signature'));
});
