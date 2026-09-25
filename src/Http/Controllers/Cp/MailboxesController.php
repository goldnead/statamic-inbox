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
use Illuminate\Validation\ValidationException;
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

        $this->requirePasswordOnChange($mailbox, $data);

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        $hostsChanged = $this->changed($mailbox, $data, ['imap_host', 'smtp_host']);

        $mailbox->fill($data);

        if ($hostsChanged) {
            // Another server: its UIDs, its Sent folder and its Gmail-ness are
            // all different. What the form states explicitly is kept.
            $mailbox->forceFill([
                'last_uid_inbox' => 0, 'last_uid_sent' => 0,
                'uidvalidity_inbox' => null, 'uidvalidity_sent' => null,
            ]);

            if (! array_key_exists('append_sent', $data)) {
                $mailbox->append_sent = ! Mailbox::isGmailHost($mailbox->imap_host) && ! Mailbox::isGmailHost($mailbox->smtp_host);
            }

            if (! array_key_exists('sent_folder', $data)) {
                $mailbox->sent_folder = null;
            }
        }

        $mailbox->save();

        if ($hostsChanged && ! $mailbox->sent_folder) {
            $this->detectSentFolder($mailbox, app(MailboxClientFactory::class));
        }

        return response()->json(['mailbox' => $this->present($mailbox->fresh() ?? $mailbox)]);
    }

    /**
     * IMAP and SMTP separately, so the form can say which half is wrong.
     *
     * Tests the stored settings, or the ones the form sends before saving
     * them. Settings that point the stored password somewhere else need the
     * password typed again, exactly as on update.
     */
    public function test(Request $request, int $inboxMailbox, MailboxClientFactory $clients, TransportFactory $transports): JsonResponse
    {
        Gate::authorize('manage inbox mailboxes');

        $mailbox = Mailbox::query()->findOrFail($inboxMailbox);

        $overrides = $request->validate([
            'imap_host' => ['sometimes', 'string', 'max:255', $this->hostRule()],
            'imap_port' => ['sometimes', 'integer', 'between:1,65535'],
            'imap_encryption' => ['sometimes', 'in:ssl,tls,starttls,none'],
            'username' => ['sometimes', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string'],
            'smtp_host' => ['sometimes', 'string', 'max:255', $this->hostRule()],
            'smtp_port' => ['sometimes', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['sometimes', 'in:ssl,tls,starttls,none'],
        ]);

        $this->requirePasswordOnChange($mailbox, $overrides);

        if (($overrides['password'] ?? '') === '') {
            unset($overrides['password']);
        }

        // Never saved: a copy carrying the form's values.
        // Keeps the id so it is recognisably this mailbox; `exists` is false,
        // so a stray save() would fail on the key rather than overwrite it.
        $candidate = $mailbox->replicate()->forceFill($overrides);
        $candidate->setAttribute($mailbox->getKeyName(), $mailbox->getKey());

        $imap = $this->attempt($candidate, $mailbox, fn () => $clients->for($candidate)->check());
        $smtp = $this->attempt($candidate, $mailbox, function () use ($transports, $candidate) {
            $transport = $transports->for($candidate);

            if ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }
        });

        return response()->json(['imap' => $imap, 'smtp' => $smtp]);
    }

    /** The settings that decide where the stored password is sent. */
    public const PASSWORD_BOUND = ['imap_host', 'smtp_host', 'username', 'imap_port', 'smtp_port'];

    /**
     * Without this, changing the host to one's own server and pressing
     * "Test connection" would hand the stored password to that server.
     *
     * @param  array<string, mixed>  $data
     */
    protected function requirePasswordOnChange(Mailbox $mailbox, array $data): void
    {
        if (($data['password'] ?? '') === '' && $this->changed($mailbox, $data, self::PASSWORD_BOUND)) {
            throw ValidationException::withMessages([
                'password' => __('Enter the password again: the server, port or login changed.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    protected function changed(Mailbox $mailbox, array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && strtolower(trim((string) $data[$field])) !== strtolower(trim((string) $mailbox->{$field}))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: bool, error: string|null} */
    protected function attempt(Mailbox $candidate, Mailbox $stored, callable $check): array
    {
        try {
            $check();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $error = Redactor::mask(Redactor::message($e, $candidate), $stored);
            $mailbox = $stored;
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
        $host = $this->hostRule();

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

    protected function hostRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                app(HostGuard::class)->check((string) $value);
            } catch (UnsafeHostException $e) {
                $fail($e->getMessage());
            }
        };
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
