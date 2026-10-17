<?php

/*
 * The settings sub-item hangs under the Tools entry by its exact name
 * (brand-context finds the parent with `$nav->find($section, $name)`). The
 * tools entry carries the unread count in its label, so the name the settings
 * read must carry it too, or the sub-item silently falls back to the general page.
 */

use Goldnead\StatamicInbox\Support\Settings;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('names the settings parent exactly as the Postfach nav entry is named while mail is unread', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml', '06-same-subject-other-sender.eml']]);
    acceptAllConversations();

    $nav = new class
    {
        public array $names = [];

        public function create(string $name): object
        {
            $this->names[] = $name;

            return new class
            {
                public function __call($method, $arguments)
                {
                    return $this;
                }
            };
        }
    };

    foreach ($this->navCallbacks as $callback) {
        $callback($nav);
    }

    $navName = collect($nav->names)->first(fn (string $name) => str_starts_with($name, 'Postfach'));

    expect($navName)->toBe('Postfach (2)')
        ->and(Settings::settingsNavParent())->toBe(['Tools', $navName]);
});
