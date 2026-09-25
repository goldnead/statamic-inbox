<?php

/*
 * "Entwurf vorschlagen": the AI gets the recent messages, the contact's name
 * and notes, and the brand's style prompt, and the result lands in the form.
 * Nothing is ever sent from here.
 */

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Ai\DraftSuggester;
use Goldnead\StatamicInbox\Ai\DraftUnavailable;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('inbox.ai.api_key', 'sk-ant-test-key');
    config()->set('inbox.ai.style_prompt', 'Du-Form, kurz, herzlich, Gruß "Liebe Grüße, Adrian".');

    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox();

    $contact = Contact::create([
        'email' => 'anna.beispiel@example.com',
        'first_name' => 'Anna',
        'last_name' => 'Beispiel',
    ]);
    LeadHub::addNote($contact->id, 'Kammerchor, Sopran, Höhe ist das Thema.');

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $this->conversation = Conversation::sole();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => "Hallo Anna,\n\nprima, dann bis Dienstag!\n\nLiebe Grüße, Adrian"]],
            'stop_reason' => 'end_turn',
        ]),
    ]);
});

it('returns a draft and sends nothing', function () {
    $draft = app(DraftSuggester::class)->suggest($this->conversation, 'Termin bestätigen');

    expect($draft)->toBe("Hallo Anna,\n\nprima, dann bis Dienstag!\n\nLiebe Grüße, Adrian")
        ->and($this->smtp->transport->messages())->toHaveCount(0)
        ->and(Message::where('direction', 'out')->where('folder', '!=', 'Sent')->count())->toBe(0)
        ->and($this->conversation->messages()->count())->toBe(3);
});

it('sends the thread, the contact, the style and the instruction to the Messages API', function () {
    app(DraftSuggester::class)->suggest($this->conversation, 'Termin bestätigen');

    Http::assertSent(function (Request $request) {
        $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-test-key')
            && $request->hasHeader('anthropic-version')
            && $request['model'] === config('inbox.ai.model')
            && str_contains($body, 'Dienstag passt super')
            && str_contains($body, 'Anna Beispiel')
            && str_contains($body, 'Höhe ist das Thema')
            && str_contains($body, 'Du-Form, kurz, herzlich')
            && str_contains($body, 'Termin bestätigen');
    });
});

it('defaults to the current Claude model', function () {
    expect(config('inbox.ai.model'))->toBe('claude-sonnet-5');
});

it('refuses without an API key, without calling anything', function () {
    config()->set('inbox.ai.api_key', null);

    expect(fn () => app(DraftSuggester::class)->suggest($this->conversation))
        ->toThrow(DraftUnavailable::class);

    Http::assertNothingSent();
});

it('turns an API error into DraftUnavailable without leaking the key', function () {
    // A fresh factory: fakes accumulate, and the success stub from beforeEach
    // would otherwise answer first.
    Http::swap(new Factory);
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['message' => 'overloaded']], 529)]);

    try {
        app(DraftSuggester::class)->suggest($this->conversation);
        $this->fail('Expected DraftUnavailable.');
    } catch (DraftUnavailable $e) {
        expect($e->getMessage())->not->toContain('sk-ant-test-key');
    }
});

it('offers the draft from the CP to someone who may reply', function () {
    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson('/cp/inbox/conversations/'.$this->conversation->id.'/draft', ['instruction' => 'kurz'])
        ->assertOk()
        ->assertJson(['text' => "Hallo Anna,\n\nprima, dann bis Dienstag!\n\nLiebe Grüße, Adrian"]);

    expect($this->smtp->transport->messages())->toHaveCount(0);
});

it('refuses the draft to someone who may only read', function () {
    $this->actingAs(inboxCpUser(['view inbox']))
        ->postJson('/cp/inbox/conversations/'.$this->conversation->id.'/draft')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('says so when email templates are not installed', function () {
    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson('/cp/inbox/conversations/'.$this->conversation->id.'/template', ['slug' => 'termin'])
        ->assertStatus(422)
        ->assertJsonPath('message', __('Email templates are not installed.'));
});
