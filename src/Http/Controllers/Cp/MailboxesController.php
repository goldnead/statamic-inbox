<?php

namespace Goldnead\StatamicInbox\Http\Controllers\Cp;

use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Support\HostGuard;
use Goldnead\StatamicInbox\Support\Redactor;
use Goldnead\StatamicInbox\Support\UnsafeHostException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Setting up mailboxes. The password goes in, never out: every response
 * carries `has_password` instead, and an empty password on update keeps the
 * stored one (the form only ever shows a placeholder).
 */
class MailboxesController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage inbox mailboxes');

        // Installed but not migrated yet: an empty screen that says so, not a 500.
        if (! Schema::hasTable('inbox_mailboxes')) {
            Log::warning('inbox: the inbox tables are missing; run php artisan migrate.');

            return Inertia::render('inbox::Mailboxes/Index', [
                'setupRequired' => __('The inbox tables are missing. Run php artisan migrate.'),
                'mailboxes' => [],
            ]);
        }

        return Inertia::render('inbox::Mailboxes/Index', [
            'mailboxes' => Mailbox::query()->orderBy('name')->get()->map(fn (Mailbox $m) => $this->present($m))->all(),
            'presets' => config('inbox.presets', []),
        ]);
    }

    public function edit(int $inboxMailbox): Response
    {
        Gate::authorize('manage inbox mailboxes');

        return Inertia::render('inbox::Mailboxes/Edit', [
            'mailbox' => $this->present(Mailbox::query()->findOrFail($inboxMailbox)),
            'presets' => config('inbox.presets', []),
        ]);
    }

    public function store(Request $request, MailboxClientFactory $clients): JsonResponse
    {
        Gate::authorize('manage inbox mailboxes');

        $data = $this->validated($request, null);

        $mailbox = Mailbox::create($data);

        if (! $mailbox->sent_folder) {
            $this->detectSentFolder($mailbox, $clients);
        }

        return response()->json(['mailbox' => $this->present($mailbox->fresh() ?? $mailbox)], 201);
    }

    public function update(Request $request, int $inboxMailbox): JsonResponse
    {
        Gate::authorize('manage inbox mailboxes');

        $mailbox = Mailbox::query()->findOrFail($inboxMailbox);
        $data = $this->validated($request, $mailbox);

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        $mailbox->fill($data)->save();

        return response()->json(['mailbox' => $this->present($mailbox->fresh() ?? $mailbox)]);
    }

    /** IMAP and SMTP separately, so the form can say which half is wrong. */
    public function test(int $inboxMailbox, MailboxClientFactory $clients, TransportFactory $transports): JsonResponse
    {
        Gate::authorize('manage inbox mailboxes');

        $mailbox = Mailbox::query()->findOrFail($inboxMailbox);

        $imap = $this->attempt($mailbox, fn () => $clients->for($mailbox)->check());
        $smtp = $this->attempt($mailbox, function () use ($transports, $mailbox) {
            $transport = $transports->for($mailbox);

            if ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }
        });

        return response()->json(['imap' => $imap, 'smtp' => $smtp]);
    }

    /** @return array{ok: bool, error: string|null} */
    protected function attempt(Mailbox $mailbox, callable $check): array
    {
        try {
            $check();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $error = Redactor::message($e, $mailbox);
            Log::info('inbox: connection test failed.', ['mailbox' => $mailbox->id, 'error' => $error]);

            return ['ok' => false, 'error' => $error];
        }
    }

    protected function detectSentFolder(Mailbox $mailbox, MailboxClientFactory $clients): void
    {
        try {
            $folder = $clients->for($mailbox)->detectSentFolder();
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => Redactor::message($e, $mailbox)])->save();

            return;
        }

        if ($folder !== null) {
            $mailbox->forceFill(['sent_folder' => $folder])->save();
        }
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Mailbox $mailbox): array
    {
        $host = function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                app(HostGuard::class)->check((string) $value);
            } catch (UnsafeHostException $e) {
                $fail($e->getMessage());
            }
        };

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255', $host],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => $mailbox ? ['nullable', 'string'] : ['required', 'string'],
            'smtp_host' => ['required', 'string', 'max:255', $host],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,starttls,none'],
            'inbox_folder' => ['nullable', 'string', 'max:255'],
            'sent_folder' => ['nullable', 'string', 'max:255'],
            'append_sent' => ['nullable', 'boolean'],
            'import_since' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ]);

        foreach (['inbox_folder', 'append_sent', 'import_since', 'active', 'sent_folder'] as $optional) {
            if (! array_key_exists($optional, $data) || $data[$optional] === null) {
                unset($data[$optional]);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function present(Mailbox $mailbox): array
    {
        return [
            ...collect($mailbox->toArray())->except(['password'])->all(),
            'has_password' => (string) $mailbox->getRawOriginal('password') !== '',
        ];
    }
}
