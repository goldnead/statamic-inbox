<?php

namespace Goldnead\StatamicInbox\Integrations;

use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Events\InboxMessageSent;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Contracts\Foundation\Application;

/**
 * The optional hooks into statamic-activity and statamic-automations.
 *
 * Each is registered only when its addon is installed, checked by class
 * name, and at most once per process: Statamic fires `booted` callbacks more
 * than once, and a trigger registered twice would run every automation twice.
 */
class SuiteBridges
{
    public const ACTIVITY_REGISTRY = 'Goldnead\\Activity\\Producers\\ProducerRegistry';

    public const IDENTITY = 'Goldnead\\IdentityContracts\\Identity';

    public const AUTOMATIONS = 'Goldnead\\StatamicAutomations\\Automations';

    protected bool $registered = false;

    public function __construct(protected Application $app) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $this->registerActivity();
        $this->registerAutomations();
    }

    protected function registerActivity(): void
    {
        if (! class_exists(self::ACTIVITY_REGISTRY) || ! class_exists(self::IDENTITY)) {
            return;
        }

        $registry = $this->app->make(self::ACTIVITY_REGISTRY);

        $registry->register(
            InboxMessageReceived::class,
            fn (InboxMessageReceived $event) => $this->activity($event->message, 'inbox.email_received'),
            'inbox.email_received'
        );

        $registry->register(
            InboxMessageSent::class,
            fn (InboxMessageSent $event) => $this->activity($event->message, 'inbox.email_sent'),
            'inbox.email_sent'
        );
    }

    /** @return array<string, mixed> */
    protected function activity(Message $message, string $type): array
    {
        $identity = self::IDENTITY;

        return [
            'actor' => new $identity(
                type: $identity::TYPE_CONTACT,
                email: $message->conversation->counterpart_email,
                name: $message->direction === Message::IN ? $message->from_name : null,
            ),
            'subject' => $message,
            'dedupe_key' => $type.':'.$message->message_id,
            'properties' => [
                'conversation_id' => $message->conversation_id,
                'mailbox_id' => $message->mailbox_id,
                'subject' => $message->subject,
            ],
        ];
    }

    protected function registerAutomations(): void
    {
        if (! class_exists(self::AUTOMATIONS) || ! $this->app->bound('automations')) {
            return;
        }

        $this->app->make('automations')->registerEventTrigger(InboxMessageReceived::class, [
            'handle' => 'inbox_email_received',
            'label' => __('Email received'),
            'group' => __('Postfach'),
            'description' => __('A new mail arrived in a mailbox of the inbox.'),
            'payload' => fn (InboxMessageReceived $event) => [
                'message' => [
                    'id' => $event->message->id,
                    'subject' => $event->message->subject,
                    'from_email' => $event->message->from_email,
                    'from_name' => $event->message->from_name,
                    'text' => $event->message->body_stripped,
                ],
                'conversation' => [
                    'id' => $event->message->conversation_id,
                    'contact_id' => $event->message->conversation->contact_id,
                ],
                'email' => $event->message->from_email,
            ],
        ]);
    }
}
