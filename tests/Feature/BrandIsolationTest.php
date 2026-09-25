<?php

/*
 * Spec test 10: brands are separate. Mailboxes, conversations and messages of
 * brand A are invisible in brand B, and a fetch, which has no request and so
 * no brand of its own, writes into the brand that owns the mailbox.
 */

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;

beforeEach(function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->imap = fakeImap();

    $this->mailboxA = BrandContext::runFor($this->brandA, fn () => inboxMailbox());
    $this->mailboxB = BrandContext::runFor($this->brandB, fn () => inboxMailbox([
        'email' => 'info@chor.test',
        'username' => 'info@chor.test',
    ]));
});

it('gives each mailbox the brand it was created in', function () {
    BrandContext::withoutBrandScope(function () {
        expect(Mailbox::find($this->mailboxA->id)->brand_id)->toBe($this->brandA->id)
            ->and(Mailbox::find($this->mailboxB->id)->brand_id)->toBe($this->brandB->id);
    });
});

it('writes a fetch into the brand that owns the mailbox, whatever brand is current', function () {
    $this->imap->client($this->mailboxA)->deliver('INBOX', mailFixture('01-new-thread.eml'));

    BrandContext::setCurrent($this->brandB);
    $this->artisan('inbox:fetch')->assertSuccessful();

    BrandContext::withoutBrandScope(function () {
        expect(Conversation::sole()->brand_id)->toBe($this->brandA->id)
            ->and(Message::sole()->brand_id)->toBe($this->brandA->id);
    });
});

it('hides brand A mailboxes, conversations and messages from brand B', function () {
    $this->imap->client($this->mailboxA)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->artisan('inbox:fetch')->assertSuccessful();

    $conversation = BrandContext::withoutBrandScope(fn () => Conversation::sole());
    $message = BrandContext::withoutBrandScope(fn () => Message::sole());

    BrandContext::setCurrent($this->brandB);

    expect(Mailbox::pluck('id')->all())->toBe([$this->mailboxB->id])
        ->and(Conversation::find($conversation->id))->toBeNull()
        ->and(Message::find($message->id))->toBeNull()
        ->and(Conversation::count())->toBe(0);

    BrandContext::setCurrent($this->brandA);

    expect(Conversation::find($conversation->id))->not->toBeNull()
        ->and(Message::find($message->id))->not->toBeNull();
});

it('dedupes per mailbox, so the same mail in two brands is stored in both', function () {
    $this->imap->client($this->mailboxA)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->imap->client($this->mailboxB)->deliver('INBOX', mailFixture('01-new-thread.eml'));

    $this->artisan('inbox:fetch')->assertSuccessful();

    BrandContext::withoutBrandScope(function () {
        expect(Message::where('message_id', 'CAanna001+x7Qk2@mail.gmail.com')->count())->toBe(2)
            ->and(Conversation::pluck('brand_id')->sort()->values()->all())
            ->toBe([$this->brandA->id, $this->brandB->id]);
    });
});

it('links only the contact of the mailbox brand', function () {
    // Anna is a contact in brand B only.
    $contactB = BrandContext::runFor($this->brandB, fn () => Contact::create(['email' => 'anna.beispiel@example.com']));

    $this->imap->client($this->mailboxA)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->imap->client($this->mailboxB)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->artisan('inbox:fetch')->assertSuccessful();

    BrandContext::withoutBrandScope(function () use ($contactB) {
        expect(Conversation::where('brand_id', $this->brandA->id)->sole()->contact_id)->toBeNull()
            ->and(Conversation::where('brand_id', $this->brandB->id)->sole()->contact_id)->toEqual($contactB->id);
    });
});
