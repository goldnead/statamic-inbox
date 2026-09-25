<?php

/*
 * Spec test 5: a direct reply is refused on a hard bounce, a complaint or an
 * address known to be invalid (decided 25.09.2026), not for any other entry
 * on the suppression list, and the check fails closed: when the list cannot
 * be read, nothing goes out.
 *
 * Someone who writes to you has not objected to an answer. A newsletter
 * opt-out never reaches statamic-suppression at all (it lives in marketing's
 * subscriptions, which the inbox does not consult); the nearest thing on the
 * list is a `manual` block, and that must not stop a reply either.
 */

use Goldnead\StatamicInbox\Exceptions\ReplyRefused;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\Suppression\Facades\Suppression;
use Goldnead\Suppression\Reasons;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox();

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $this->conversation = Conversation::sole();
});

it('refuses a reply to an address that hard-bounced', function () {
    Suppression::suppress('anna.beispiel@example.com', Reasons::HARD_BOUNCE);

    expect(fn () => app(ReplySender::class)->send($this->conversation, 'Hallo Anna'))
        ->toThrow(ReplyRefused::class);

    expect($this->smtp->transport->messages())->toHaveCount(0)
        ->and(Message::where('direction', 'out')->count())->toBe(0)
        ->and($this->imap->client($this->mailbox)->appended)->toBe([]);
});

it('refuses a reply to an address that complained', function () {
    Suppression::suppress('anna.beispiel@example.com', Reasons::COMPLAINT);

    expect(fn () => app(ReplySender::class)->send($this->conversation, 'Hallo Anna'))
        ->toThrow(ReplyRefused::class);

    expect($this->smtp->transport->messages())->toHaveCount(0);
});

it('refuses a reply to an address known to be invalid', function () {
    Suppression::suppress('anna.beispiel@example.com', Reasons::INVALID_EMAIL);

    expect(fn () => app(ReplySender::class)->send($this->conversation, 'Hallo Anna'))
        ->toThrow(ReplyRefused::class);

    expect($this->smtp->transport->messages())->toHaveCount(0);
});

it('sends again once the suppression is released', function () {
    Suppression::suppress('anna.beispiel@example.com', Reasons::HARD_BOUNCE);
    Suppression::release('anna.beispiel@example.com', ['actor' => 'test']);

    app(ReplySender::class)->send($this->conversation, 'Hallo Anna');

    expect($this->smtp->transport->messages())->toHaveCount(1);
});

it('matches the suppressed address regardless of case', function () {
    Suppression::suppress('ANNA.Beispiel@example.com', Reasons::HARD_BOUNCE);

    expect(fn () => app(ReplySender::class)->send($this->conversation, 'Hallo Anna'))
        ->toThrow(ReplyRefused::class);
});

it('sends despite a suppression for any other reason', function (string $reason) {
    Suppression::suppress('anna.beispiel@example.com', $reason);

    app(ReplySender::class)->send($this->conversation, 'Hallo Anna');

    expect($this->smtp->transport->messages())->toHaveCount(1);
})->with([
    'manual block' => Reasons::MANUAL,
    'soft bounce threshold' => Reasons::SOFT_BOUNCE_THRESHOLD,
]);

it('sends when the address is not on the list at all', function () {
    Suppression::suppress('someone-else@example.com', Reasons::HARD_BOUNCE);

    app(ReplySender::class)->send($this->conversation, 'Hallo Anna');

    expect($this->smtp->transport->messages())->toHaveCount(1);
});

it('fails closed when the suppression list cannot be read', function () {
    Schema::drop('suppressions');

    expect(fn () => app(ReplySender::class)->send($this->conversation, 'Hallo Anna'))
        ->toThrow(ReplyRefused::class);

    expect($this->smtp->transport->messages())->toHaveCount(0);
});
