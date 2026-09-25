<?php

namespace Goldnead\StatamicInbox\Support;

use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Support\Facades\Gate;

/**
 * What a mail server or the AI said, in words a person can act on.
 *
 * The one place that turns "535 5.7.8 Authentication failed" into "Das
 * App-Passwort für Coaching wurde abgelehnt" plus the button that fixes it.
 * The inbox list, the conversation, the mailbox screens and the reply form
 * all show what this returns; the server's own text travels along as
 * `detail`, for the provider's support, and is shown folded.
 *
 * The action only appears for someone who may open where it leads.
 */
class ErrorExplainer
{
    public const SEND = 'send';

    public const FETCH = 'fetch';

    public const FILED = 'filed';

    public const TEST = 'test';

    /**
     * Checked in this order; the first match wins. Authentication before
     * connection: a refused login often mentions the connection as well.
     *
     * @var array<string, string>
     */
    protected const PATTERNS = [
        'auth' => '/\b535\b|5\.7\.8|5\.7\.0 authentication|authenticat\w* (failed|required|unsuccessful)|AUTHENTICATIONFAILED|invalid credentials|username and password not accepted|login failed|LOGIN failed|app password|password (rejected|incorrect)|\bAUTHENTICATE failed/i',
        'recipient' => '/\b550\b|\b553\b|5\.1\.1|5\.1\.10|user unknown|unknown user|no such user|recipient (address )?(rejected|refused|not found)|mailbox (unavailable|not found)|address rejected/i',
        'quota' => '/\b552\b|over ?quota|quota exceeded|mailbox (is )?full|OVERQUOTA/i',
        'folder' => '/NONEXISTENT|TRYCREATE|(folder|mailbox) .*(not found|doesn.t exist|does not exist|unknown)|no such (folder|mailbox)/i',
        'tls' => '/certificate|ssl3?_|tls handshake|SSL operation failed|crypto|wrong version number|STARTTLS/i',
        'connection' => '/connection (refused|timed out|reset|could not be established)|timed? ?out|could not connect|unable to connect|failed to connect|getaddrinfo|name or service not known|no route to host|network is unreachable|php_network|temporary failure in name resolution/i',
    ];

    /**
     * @return array{code: string, title: string, text: string, action: array{label: string, url: string}|null, detail: string}|null
     */
    public function explain(?string $raw, string $context, ?Mailbox $mailbox = null, ?string $address = null): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $code = 'unknown';

        foreach (self::PATTERNS as $candidate => $pattern) {
            // Only a send has a recipient; for IMAP "mailbox not found" means a folder.
            if ($candidate === 'recipient' && $context !== self::SEND) {
                continue;
            }

            if (preg_match($pattern, $raw) === 1) {
                $code = $candidate;
                break;
            }
        }

        $name = $mailbox?->name ?: __('this mailbox');

        [$title, $text, $action] = match ($code) {
            'auth' => [
                __('The app password for :mailbox was refused', ['mailbox' => $name]),
                __('Your mail provider did not accept the login. Create a new app password with the provider and enter it in the mailbox.'),
                $this->mailboxAction($mailbox, __('Renew password'), 'account'),
            ],
            'connection' => [
                __('The mail server of :mailbox cannot be reached', ['mailbox' => $name]),
                __('The server did not answer. Check server and port. If both are right, the provider has a problem and the next attempt runs by itself.'),
                $this->mailboxAction($mailbox, __('Check servers'), 'servers'),
            ],
            'tls' => [
                __('The encrypted connection to :mailbox failed', ['mailbox' => $name]),
                __('Usually encryption and port do not match, for example SSL/TLS on port 587. Check both in the mailbox.'),
                $this->mailboxAction($mailbox, __('Check servers'), 'servers'),
            ],
            'recipient' => [
                $address
                    ? __(':address does not accept mail', ['address' => $address])
                    : __('The address does not accept mail'),
                __('The receiving server refused the address. Check it for typos or reach the person another way.'),
                null,
            ],
            'quota' => [
                __('The mailbox :mailbox is full', ['mailbox' => $name]),
                __('The provider takes no more mail until there is space again. Delete or archive old mail in your mail program.'),
                null,
            ],
            'folder' => [
                __('A folder of :mailbox was not found', ['mailbox' => $name]),
                __('Your provider names this folder differently. Check the folder names in the mailbox.'),
                $this->mailboxAction($mailbox, __('Check folders'), 'folders'),
            ],
            default => [
                match ($context) {
                    self::SEND => __('The reply could not be sent'),
                    self::FETCH => __('Fetching :mailbox failed', ['mailbox' => $name]),
                    self::FILED => __('The copy for the Sent folder could not be stored'),
                    default => __('The connection failed'),
                },
                __('The server gave a reason this page does not know yet. The details below help your provider\'s support.'),
                $this->mailboxAction($mailbox, __('Check mailbox'), 'servers'),
            ],
        };

        return ['code' => $code, 'title' => $title, 'text' => $text, 'action' => $action, 'detail' => $raw];
    }

    /**
     * The AI draft, by HTTP status first and message second.
     *
     * @return array{code: string, title: string, text: string, action: array{label: string, url: string}|null, detail: string}
     */
    public function explainAi(string $raw, ?int $status = null): array
    {
        $code = match (true) {
            $status === 401 || $status === 403
                || preg_match('/x-api-key|api.?key|authentication_error|permission_error|ANTHROPIC_API_KEY/i', $raw) === 1 => 'ai_access',
            in_array($status, [429, 503, 529], true)
                || preg_match('/overloaded|rate.?limit|too many requests/i', $raw) === 1 => 'ai_busy',
            preg_match('/could not be reached|timed? ?out|connection/i', $raw) === 1 => 'ai_offline',
            default => 'ai_unknown',
        };

        [$title, $text] = match ($code) {
            'ai_access' => [
                __('The AI access is not set up or has expired'),
                __('Whoever looks after the website needs to enter a valid AI key. Until then, write the reply yourself or use a template.'),
            ],
            'ai_busy' => [
                __('The AI is busy right now'),
                __('Please try again in a moment.'),
            ],
            'ai_offline' => [
                __('The AI could not be reached'),
                __('Please try again in a moment. If it keeps happening, the website cannot reach the AI service.'),
            ],
            default => [
                __('No draft could be suggested'),
                __('Please try again in a moment, or write the reply yourself.'),
            ],
        };

        // No button: the key lives in the server's environment, and no page
        // in the Control Panel can change it.
        return ['code' => $code, 'title' => $title, 'text' => $text, 'action' => null, 'detail' => $raw];
    }

    /** @return array{label: string, url: string}|null */
    protected function mailboxAction(?Mailbox $mailbox, string $label, string $tab): ?array
    {
        if ($mailbox === null || ! $mailbox->exists || ! Gate::allows('manage inbox mailboxes')) {
            return null;
        }

        return ['label' => $label, 'url' => cp_route('inbox.mailboxes.edit', $mailbox->id).'?tab='.$tab];
    }
}
