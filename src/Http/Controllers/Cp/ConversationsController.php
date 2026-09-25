<?php

namespace Goldnead\StatamicInbox\Http\Controllers\Cp;

use Goldnead\StatamicInbox\Ai\DraftSuggester;
use Goldnead\StatamicInbox\Ai\DraftUnavailable;
use Goldnead\StatamicInbox\Exceptions\ReplyRefused;
use Goldnead\StatamicInbox\Integrations\EmailTemplateFiller;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\Sending\SendFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ConversationsController extends Controller
{
    public const TABS = ['open', 'waiting', 'closed', 'snoozed'];

    public function index(Request $request): Response
    {
        Gate::authorize('view inbox');

        // Installed but not migrated yet: an empty screen that says so, not a 500.
        if (! Schema::hasTable('inbox_conversations')) {
            Log::warning('inbox: the inbox tables are missing; run php artisan migrate.');

            return Inertia::render('inbox::Conversations/Index', [
                'setupRequired' => __('The inbox tables are missing. Run php artisan migrate.'),
            ]);
        }

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'open';
        $now = Carbon::now();

        // The excerpt as a subquery: one query for the page, not one per row.
        $query = Conversation::query()
            ->select('inbox_conversations.*')
            ->addSelect(['excerpt' => Message::query()
                ->select('body_stripped')
                ->whereColumn('inbox_messages.conversation_id', 'inbox_conversations.id')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->with('mailbox:id,name,email')
            ->when($request->query('mailbox'), fn ($q, $id) => $q->where('mailbox_id', (int) $id))
            ->when($request->query('q'), function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%';
                $q->where(fn ($q) => $q->where('subject', 'like', $like)->orWhere('counterpart_email', 'like', $like));
            });

        if ($tab === 'snoozed') {
            $query->where('snoozed_until', '>', $now);
        } else {
            $query->where('status', $tab)
                ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now));
        }

        return Inertia::render('inbox::Conversations/Index', [
            'tab' => $tab,
            'conversations' => $query->orderByDesc('last_message_at')->paginate(50)->through(fn (Conversation $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'counterpart_email' => $c->counterpart_email,
                'contact_id' => $c->contact_id,
                'status' => $c->status,
                'unread' => $c->unread,
                'snoozed_until' => $c->snoozed_until?->toIso8601String(),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'excerpt' => Str::limit((string) $c->getAttribute('excerpt'), 140),
                'mailbox' => $c->mailbox?->only(['id', 'name', 'email']),
            ]),
            'mailboxes' => Mailbox::query()->orderBy('name')->get(['id', 'name', 'email']),
            'unreadCount' => Conversation::query()->where('unread', true)->count(),
        ]);
    }

    public function show(int $inboxConversation, LeadHubContacts $contacts): Response
    {
        Gate::authorize('view inbox');

        $conversation = Conversation::query()->with('mailbox')->findOrFail($inboxConversation);

        if ($conversation->unread) {
            $conversation->forceFill(['unread' => false])->save();
        }

        return Inertia::render('inbox::Conversations/Show', [
            'conversation' => $conversation->only([
                'id', 'subject', 'counterpart_email', 'contact_id', 'status', 'snoozed_until', 'last_message_at',
            ]),
            'mailbox' => $conversation->mailbox?->only(['id', 'name', 'email']),
            'messages' => $conversation->messages()->with('attachments')->get()->map(function (Message $m) {
                $attachments = $m->attachments->map(fn (Attachment $a) => [
                    ...$a->only(['id', 'filename', 'mime', 'size', 'content_id']),
                    'url' => cp_route('inbox.attachments.show', $a->id),
                ]);

                return [
                    ...$m->only([
                        'id', 'direction', 'from_email', 'from_name', 'to', 'cc', 'subject', 'text',
                        'html_sanitized', 'body_stripped', 'has_remote_images', 'send_error', 'filed_error',
                    ]),
                    'sent_at' => $m->sent_at?->toIso8601String(),
                    'attachments' => $attachments->all(),
                    // data-inbox-cid="…" in html_sanitized → this URL, served
                    // through the permission-checked attachment route.
                    'inline_images' => $attachments->filter(fn ($a) => $a['content_id'] !== null)
                        ->mapWithKeys(fn ($a) => [$a['content_id'] => $a['url']])
                        ->all(),
                ];
            })->all(),
            'contact' => $conversation->contact_id ? $contacts->findById($conversation->contact_id) : null,
            'leadhub' => $contacts->available(),
            'templates' => app(EmailTemplateFiller::class)->available(),
            'ai' => (string) config('inbox.ai.api_key') !== '',
        ]);
    }

    /** Status, snooze and contact link. */
    public function update(Request $request, int $inboxConversation, LeadHubContacts $contacts): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);

        $data = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', Conversation::STATUSES)],
            'snoozed_until' => ['sometimes', 'nullable', 'date'],
            'unread' => ['sometimes', 'boolean'],
            'contact_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($contacts) {
                // Only a contact LeadHub knows, in this brand; without LeadHub, none.
                if ($value !== null && $contacts->findById((int) $value) === null) {
                    $fail(__('There is no such contact.'));
                }
            }],
        ]);

        $conversation->fill($data)->save();

        return response()->json(['conversation' => $conversation->fresh()]);
    }

    /**
     * "Kontakt anlegen": creates the LeadHub contact for the other side, on a
     * click and never on its own, then links the conversation to it.
     */
    public function createContact(int $inboxConversation, LeadHubContacts $contacts): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);

        if (! $contacts->available()) {
            return response()->json(['message' => __('LeadHub is not installed.')], 422);
        }

        $name = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', Message::IN)
            ->whereNotNull('from_name')
            ->latest('sent_at')
            ->value('from_name');

        $contact = $contacts->create($conversation->counterpart_email, $name);

        $conversation->forceFill(['contact_id' => (int) $contact['id']])->save();

        return response()->json(['contact' => $contact, 'conversation' => $conversation->fresh()], 201);
    }

    public function reply(Request $request, int $inboxConversation, ReplySender $sender): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:100000'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        try {
            $message = $sender->send($conversation, $data['text'], array_values($request->file('attachments', [])));
        } catch (ReplyRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SendFailed $e) {
            // Stored with its error; the form keeps the text for another try.
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['message' => $message->only(['id', 'message_id', 'direction', 'send_error', 'filed_error'])], 201);
    }

    public function draft(Request $request, int $inboxConversation, DraftSuggester $suggester): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);
        $instruction = $request->validate(['instruction' => ['nullable', 'string', 'max:1000']])['instruction'] ?? null;

        try {
            return response()->json(['text' => $suggester->suggest($conversation, $instruction)]);
        } catch (DraftUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function template(Request $request, int $inboxConversation, EmailTemplateFiller $templates): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);
        $slug = $request->validate(['slug' => ['required', 'string', 'max:255']])['slug'];

        if (! $templates->available()) {
            return response()->json(['message' => __('Email templates are not installed.')], 422);
        }

        $filled = $templates->fill($conversation, $slug);

        return $filled === null
            ? response()->json(['message' => __('No template with that handle.')], 404)
            : response()->json($filled);
    }
}
