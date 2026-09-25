<?php

namespace Goldnead\StatamicInbox\Http\Controllers\Cp;

use Goldnead\StatamicInbox\Ai\DraftSuggester;
use Goldnead\StatamicInbox\Ai\DraftUnavailable;
use Goldnead\StatamicInbox\Exceptions\ReplyRefused;
use Goldnead\StatamicInbox\Integrations\EmailTemplateFiller;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\Sending\SendFailed;
use Goldnead\StatamicInbox\Support\ErrorExplainer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\CP\Column;
use Statamic\Facades\Scope;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

class ConversationsController extends Controller
{
    use QueriesFilters;

    public const TABS = ['open', 'waiting', 'closed', 'snoozed'];

    /** The filter key InboxMailbox answers to. */
    public const FILTER_KEY = 'inbox-conversations';

    /**
     * The page, and the JSON core's Listing asks the same URL for: rows plus
     * `meta.columns` on every response, paginated, searched and filtered.
     */
    public function index(Request $request, LeadHubContacts $contacts, ErrorExplainer $explainer): Response|JsonResponse
    {
        Gate::authorize('view inbox');

        // Installed but not migrated yet: an empty screen that says so, not a 500.
        if (! Schema::hasTable('inbox_conversations')) {
            Log::warning('inbox: the inbox tables are missing; run php artisan migrate.');

            return Inertia::render('inbox::Conversations/Index', [
                'setupRequired' => __('The inbox tables are missing. Run php artisan migrate.'),
            ]);
        }

        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'open';

        if ($request->wantsJson()) {
            return $this->listing($request, $tab, $contacts);
        }

        return Inertia::render('inbox::Conversations/Index', [
            'tab' => $tab,
            'tabCounts' => collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => $this->inTab(Conversation::query(), $t)->count()])->all(),
            'columns' => $this->columns(),
            'filters' => Scope::filters(self::FILTER_KEY),
            'listingUrl' => cp_route('inbox.index'),
            'mailboxes' => Mailbox::query()->orderBy('name')->get()->map(fn (Mailbox $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'active' => (bool) $m->active,
                'last_error' => $m->last_error,
                'last_error_scope' => $m->last_error_scope,
                'folder_errors' => $m->folder_errors ?? [],
                'inbox_folder' => $m->inbox_folder,
                'sent_folder' => $m->sent_folder,
                // In words, with the fix: the whole mailbox, and each folder.
                'problem' => $m->last_error_scope === 'mailbox'
                    ? $explainer->explain($m->last_error, ErrorExplainer::FETCH, $m)
                    : null,
                'folder_problems' => collect($m->folder_errors ?? [])
                    ->map(fn ($error) => $explainer->explain((string) $error, ErrorExplainer::FETCH, $m))
                    ->all(),
                'last_fetched_at' => $m->last_fetched_at?->toIso8601String(),
                'edit_url' => cp_route('inbox.mailboxes.edit', $m->id),
            ])->all(),
            // Messages the fetch gave up on: skipped for good, so said once.
            'failures' => FetchFailure::query()
                ->whereNotNull('gave_up_at')
                ->selectRaw('mailbox_id, folder, count(*) as count, max(id) as latest')
                ->groupBy('mailbox_id', 'folder')
                ->get()
                // `latest` lets the page hide a notice until a new failure arrives.
                ->map(fn ($f) => [
                    'mailbox_id' => (int) $f->mailbox_id,
                    'folder' => (string) $f->folder,
                    'count' => (int) $f->getAttribute('count'),
                    'latest' => (int) $f->getAttribute('latest'),
                ])
                ->all(),
            'unreadCount' => Conversation::query()->where('unread', true)->count(),
            'canReply' => Gate::allows('reply inbox'),
            'canManageMailboxes' => Gate::allows('manage inbox mailboxes'),
            'mailboxesUrl' => cp_route('inbox.mailboxes.index'),
            'createMailboxUrl' => cp_route('inbox.mailboxes.create'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    protected function columns(): array
    {
        return [
            Column::make('counterpart')->label(__('Contact'))->sortable(false)->required(true)->toArray(),
            Column::make('subject')->label(__('Subject'))->sortable(false)->toArray(),
            Column::make('mailbox')->label(__('Mailbox'))->sortable(false)->defaultVisibility(Mailbox::query()->count() > 1)->visible(Mailbox::query()->count() > 1)->toArray(),
            Column::make('last_message_at')->label(__('Last message'))->sortable(true)->toArray(),
        ];
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    protected function inTab(Builder $query, string $tab): Builder
    {
        $now = Carbon::now();

        if ($tab === 'snoozed') {
            return $query->where('snoozed_until', '>', $now);
        }

        return $query->where('status', $tab)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now));
    }

    protected function listing(Request $request, string $tab, LeadHubContacts $contacts): JsonResponse
    {
        // The excerpt, the other side's name and a failed send as subqueries:
        // one query for the page, not three per row.
        $latest = fn () => Message::query()
            ->whereColumn('inbox_messages.conversation_id', 'inbox_conversations.id')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(1);

        $query = Conversation::query()
            ->select('inbox_conversations.*')
            ->addSelect([
                'excerpt' => $latest()->select('body_stripped'),
                // An HTML-only mail has no text part; its excerpt comes from the markup.
                'excerpt_html' => $latest()->select('html_sanitized'),
                'counterpart_name' => Message::query()
                    ->select('from_name')
                    ->whereColumn('inbox_messages.conversation_id', 'inbox_conversations.id')
                    ->where('direction', Message::IN)
                    ->whereNotNull('from_name')
                    ->orderByDesc('sent_at')
                    ->limit(1),
                'has_send_error' => Message::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('inbox_messages.conversation_id', 'inbox_conversations.id')
                    ->whereNotNull('send_error'),
            ])
            ->with('mailbox:id,name,email')
            ->when($request->query('mailbox'), fn ($q, $id) => $q->where('mailbox_id', (int) $id))
            ->when($request->query('search') ?? $request->query('q'), function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%';
                $q->where(fn ($q) => $q->where('subject', 'like', $like)
                    ->orWhere('counterpart_email', 'like', $like)
                    ->orWhereExists(fn ($sub) => $sub->from('inbox_messages')
                        ->whereColumn('inbox_messages.conversation_id', 'inbox_conversations.id')
                        ->where('from_name', 'like', $like)));
            });

        $this->inTab($query, $tab);

        $filters = json_decode((string) base64_decode((string) $request->query('filters', ''), true), true);
        $badges = is_array($filters) ? $this->queryFilters($query, $filters, []) : [];

        $query->orderBy('last_message_at', $request->query('order') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        $perPage = max(1, min(500, (int) $request->query('perPage', 50)));
        $page = $query->paginate($perPage);

        $names = collect($page->items())
            ->pluck('contact_id')->filter()->unique()
            ->mapWithKeys(function ($id) use ($contacts) {
                $contact = $contacts->findById((int) $id);

                return [(int) $id => $contact === null ? null : trim((string) ($contact['full_name'] ?? ''))];
            });

        $rows = collect($page->items())->map(fn (Conversation $c) => [
            'id' => $c->id,
            'subject' => $c->subject,
            // The LeadHub name, else the name the sender gave, else the address.
            'counterpart' => ($c->contact_id ? ($names[$c->contact_id] ?? null) : null)
                ?: ($c->getAttribute('counterpart_name') ?: $c->counterpart_email),
            'counterpart_email' => $c->counterpart_email,
            'contact_id' => $c->contact_id,
            'status' => $c->status,
            'unread' => $c->unread,
            'has_send_error' => (int) $c->getAttribute('has_send_error') > 0,
            'snoozed_until' => $c->snoozed_until?->toIso8601String(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'excerpt' => $this->excerpt((string) $c->getAttribute('excerpt'), (string) $c->getAttribute('excerpt_html')),
            'mailbox' => $c->mailbox?->name,
            'show_url' => cp_route('inbox.conversations.show', $c->id),
            'update_url' => cp_route('inbox.conversations.update', $c->id),
        ])->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'columns' => $this->columns(),
                'activeFilterBadges' => $badges,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ]);
    }

    protected function excerpt(string $text, string $html): string
    {
        if (trim($text) === '' && $html !== '') {
            $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/h\d|\/li)\b[^>]*>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5);
        }

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), 140);
    }

    public function show(int $inboxConversation, LeadHubContacts $contacts, EmailTemplateFiller $templates, ErrorExplainer $explainer): Response
    {
        Gate::authorize('view inbox');

        $conversation = Conversation::query()->with('mailbox')->findOrFail($inboxConversation);

        if ($conversation->unread) {
            $conversation->forceFill(['unread' => false])->save();
        }

        $contact = $conversation->contact_id ? $contacts->findById($conversation->contact_id) : null;

        if ($contact !== null && Route::has('statamic.cp.leadhub.contacts.show')) {
            $contact['url'] = cp_route('leadhub.contacts.show', $contact['id']);
        }

        return Inertia::render('inbox::Conversations/Show', [
            'conversation' => [
                ...$conversation->only(['id', 'subject', 'counterpart_email', 'contact_id', 'status']),
                'snoozed_until' => $conversation->snoozed_until?->toIso8601String(),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ],
            'mailbox' => $conversation->mailbox?->only(['id', 'name', 'email']),
            'messages' => $conversation->messages()->with('attachments')->get()->map(function (Message $m) use ($explainer, $conversation) {
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
                    'send_problem' => $explainer->explain($m->send_error, ErrorExplainer::SEND, $conversation->mailbox, $conversation->counterpart_email),
                    'filed_problem' => $explainer->explain($m->filed_error, ErrorExplainer::FILED, $conversation->mailbox),
                    'attachments' => $attachments->all(),
                    // data-inbox-cid="…" in html_sanitized → this URL, served
                    // through the permission-checked attachment route.
                    'inline_images' => $attachments->filter(fn ($a) => $a['content_id'] !== null)
                        ->mapWithKeys(fn ($a) => [$a['content_id'] => $a['url']])
                        ->all(),
                ];
            })->all(),
            'contact' => $contact,
            'leadhub' => $contacts->available(),
            'templates' => $templates->options(),
            'ai' => (string) config('inbox.ai.api_key') !== '',
            'canReply' => Gate::allows('reply inbox'),
            'urls' => [
                'index' => cp_route('inbox.index'),
                'update' => cp_route('inbox.conversations.update', $conversation->id),
                'reply' => cp_route('inbox.conversations.reply', $conversation->id),
                'draft' => cp_route('inbox.conversations.draft', $conversation->id),
                'template' => cp_route('inbox.conversations.template', $conversation->id),
                'contact' => cp_route('inbox.conversations.contact', $conversation->id),
            ],
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

    public function reply(Request $request, int $inboxConversation, ReplySender $sender, ErrorExplainer $explainer): JsonResponse
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
            $explained = $explainer->explain($e->getMessage(), ErrorExplainer::SEND, $conversation->mailbox, $conversation->counterpart_email);

            return response()->json(['message' => $explained['title'] ?? $e->getMessage(), 'explanation' => $explained], 502);
        }

        return response()->json(['message' => $message->only(['id', 'message_id', 'direction', 'send_error', 'filed_error'])], 201);
    }

    public function draft(Request $request, int $inboxConversation, DraftSuggester $suggester, ErrorExplainer $explainer): JsonResponse
    {
        Gate::authorize('reply inbox');

        $conversation = Conversation::query()->findOrFail($inboxConversation);
        $instruction = $request->validate(['instruction' => ['nullable', 'string', 'max:1000']])['instruction'] ?? null;

        try {
            return response()->json(['text' => $suggester->suggest($conversation, $instruction)]);
        } catch (DraftUnavailable $e) {
            $explained = $explainer->explainAi($e->getMessage(), $e->status);

            return response()->json(['message' => $explained['title'], 'explanation' => $explained], 422);
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
