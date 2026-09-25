<?php

/*
 * Seed data for the playground (scripts/setup-playground.sh). No real mail
 * server: the mailboxes are fetched through the in-memory IMAP fake the test
 * suite uses, from the same fixtures plus a few written here, so every row
 * went through the real parser, sanitiser and threader.
 *
 * What it leaves behind:
 *   - "Coaching": a thread of five messages with Anna (a LeadHub contact),
 *     a quoted Gmail reply, a newsletter with blocked remote images, a mail
 *     with an inline photo and a PDF, an unlinked enquiry whose reply failed
 *     to send, one conversation waiting, one done, one snoozed, and two
 *     messages the fetch gave up on
 *   - "Chor": a mailbox the fetch cannot reach
 *   - one email template to pick in the reply form
 *
 * Usage: php8.4 scripts/playground-seed.php /path/to/playground
 */

use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Tests\Fakes\FakeMailboxClientFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;

$playground = $argv[1] ?? getcwd();
$addon = dirname(__DIR__);

require $playground.'/vendor/autoload.php';
$app = require $playground.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once $addon.'/tests/Fakes/FakeMailboxClient.php';
require_once $addon.'/tests/Fakes/FakeMailboxClientFactory.php';

// ── CP user, German ──────────────────────────────────────────────────────
$email = getenv('CP_EMAIL') ?: 'admin@example.com';
$user = User::findByEmail($email) ?: User::make()->email($email);
$user->password(getenv('CP_PASSWORD') ?: 'password')->makeSuper()->setPreference('locale', 'de')->save();
echo "user: {$email}\n";

// ── Start clean ──────────────────────────────────────────────────────────
Attachment::query()->delete();
Message::query()->delete();
Conversation::query()->delete();
FetchFailure::query()->delete();
Mailbox::query()->delete();

// ── LeadHub: Anna is a contact, Max is not ───────────────────────────────
$leadhub = 'Goldnead\\Leadhub\\Facades\\LeadHub';
if (class_exists($leadhub)) {
    $leadhub::create([
        'email' => 'anna.beispiel@example.com',
        'first_name' => 'Anna',
        'last_name' => 'Beispiel',
        'company' => 'Kammerchor Nordstadt',
        'tags' => ['Coaching', 'Online'],
        'source' => 'website',
    ]);
}

// ── Mailboxes ────────────────────────────────────────────────────────────
$coaching = Mailbox::create([
    'name' => 'Coaching',
    'email' => 'adrian@goldner.test',
    'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
    'username' => 'adrian@goldner.test',
    'password' => 'playground-app-password',
    'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
    'inbox_folder' => 'INBOX',
    'sent_folder' => 'Sent',
    'import_since' => Carbon::now()->subDays(90),
]);

$chor = Mailbox::create([
    'name' => 'Chor',
    'email' => 'chor@goldner.test',
    'imap_host' => 'imap.manitu.de', 'imap_port' => 993, 'imap_encryption' => 'ssl',
    'username' => 'chor@goldner.test',
    'password' => 'playground-app-password',
    'smtp_host' => 'smtp.manitu.de', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    'sent_folder' => 'Gesendet',
]);

// ── Mail, through the fake server and the real fetcher ───────────────────
$fixture = fn (string $name) => file_get_contents($addon.'/tests/Fixtures/mail/'.$name);

$raw = function (array $h, string $body, string $type = 'text/plain') {
    $headers = '';
    foreach ($h as $key => $value) {
        $headers .= "{$key}: {$value}\r\n";
    }

    return "MIME-Version: 1.0\r\n{$headers}Content-Type: {$type}; charset=\"UTF-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".str_replace("\n", "\r\n", $body);
};

$factory = new FakeMailboxClientFactory;
$app->instance(MailboxClientFactory::class, $factory);
$client = $factory->client($coaching);

// Anna: 01 (in), 02 (out), 03 (in, Gmail reply with quote), then two more.
$client->deliver('INBOX', $fixture('01-new-thread.eml'));
$client->deliver('Sent', $fixture('02-sent-reply.eml'));
$client->deliver('INBOX', $fixture('03-gmail-reply.eml'));
$client->deliver('Sent', $raw([
    'Date' => 'Mon, 21 Sep 2026 10:30:00 +0200',
    'Message-ID' => '<adrian-out-002@goldner.test>',
    'In-Reply-To' => '<CAanna003+Zr9Lm@mail.gmail.com>',
    'References' => '<CAanna001+x7Qk2@mail.gmail.com> <adrian-out-001@goldner.test> <CAanna003+Zr9Lm@mail.gmail.com>',
    'Subject' => 'Re: Frage zum Coaching',
    'From' => 'Adrian Goldner <adrian@goldner.test>',
    'To' => 'Anna Beispiel <anna.beispiel@example.com>',
], "Hallo Anna,\n\nprima, dann Dienstag um 18 Uhr. Den Link schicke ich dir am Vormittag.\nBring gern ein Stück mit, an dem du gerade arbeitest.\n\nLiebe Grüße\nAdrian\n"));
$client->deliver('INBOX', $raw([
    'Date' => 'Thu, 24 Sep 2026 21:12:00 +0200',
    'Message-ID' => '<CAanna005+Tq1@mail.gmail.com>',
    'In-Reply-To' => '<adrian-out-002@goldner.test>',
    'References' => '<CAanna001+x7Qk2@mail.gmail.com> <adrian-out-001@goldner.test> <CAanna003+Zr9Lm@mail.gmail.com> <adrian-out-002@goldner.test>',
    'Subject' => 'Re: Frage zum Coaching',
    'From' => 'Anna Beispiel <anna.beispiel@example.com>',
    'To' => 'Adrian Goldner <adrian@goldner.test>',
], "Hallo Adrian,\n\ndanke für die Stunde! Ich habe die Übung mit dem Strohhalm jeden Tag gemacht, die Höhe fühlt sich schon leichter an.\nKönnen wir nächste Woche wieder einen Termin machen?\n\nAnna\n\nAm Mo., 21. Sept. 2026 um 10:30 Uhr schrieb Adrian Goldner <adrian@goldner.test>:\n> Hallo Anna,\n>\n> prima, dann Dienstag um 18 Uhr. Den Link schicke ich dir am Vormittag.\n"));

// Newsletter with remote images, photo with PDF.
$client->deliver('INBOX', $fixture('07-html-tracking.eml'));
$client->deliver('INBOX', str_replace('Subject: Foto vom Konzert', 'Subject: Foto vom Konzert am Samstag', $fixture('09-inline-image.eml')));

// An enquiry from someone who is not a contact.
$client->deliver('INBOX', $raw([
    'Date' => 'Fri, 25 Sep 2026 08:41:00 +0200',
    'Message-ID' => '<max-001@chorwerk.example>',
    'Subject' => 'Stimmbildung für unseren Chor',
    'From' => 'Max Mustermann <max@chorwerk.example>',
    'To' => 'adrian@goldner.test',
], "Guten Morgen Herr Goldner,\n\nwir sind ein gemischter Chor mit 35 Leuten und suchen jemanden für einen Stimmbildungstag im November.\nHätten Sie an einem Samstag Zeit?\n\nViele Grüße\nMax Mustermann\nChorwerk Südstadt\n"));

// Three more, for the other tabs.
$client->deliver('INBOX', $raw([
    'Date' => 'Tue, 22 Sep 2026 16:05:00 +0200',
    'Message-ID' => '<lena-001@example.org>',
    'Subject' => 'Rechnung September',
    'From' => 'Lena Vogel <lena.vogel@example.org>',
    'To' => 'adrian@goldner.test',
], "Hallo Adrian,\n\nkannst du mir die Rechnung für September noch einmal schicken? Ich finde sie nicht mehr.\n\nDanke dir\nLena\n"));
$client->deliver('INBOX', $raw([
    'Date' => 'Sat, 19 Sep 2026 11:20:00 +0200',
    'Message-ID' => '<jonas-001@example.net>',
    'Subject' => 'Danke für den Workshop',
    'From' => 'Jonas Adler <jonas@example.net>',
    'To' => 'adrian@goldner.test',
], "Lieber Adrian,\n\nder Workshop am Freitag war großartig. Der ganze Tenor klingt seitdem freier.\n\nJonas\n"));
$client->deliver('INBOX', $raw([
    'Date' => 'Wed, 23 Sep 2026 09:00:00 +0200',
    'Message-ID' => '<sophie-001@example.com>',
    'Subject' => 'Probenplan Oktober',
    'From' => 'Sophie Brandt <sophie.brandt@example.com>',
    'To' => 'adrian@goldner.test',
], "Hallo Adrian,\n\nder Probenplan für Oktober kommt erst nächste Woche. Ich melde mich dann.\n\nSophie\n"));

app(MailboxFetcher::class)->fetch($coaching->fresh());

// ── States ───────────────────────────────────────────────────────────────
$bySubject = fn (string $subject) => Conversation::query()->where('subject', 'like', $subject.'%')->firstOrFail();

$bySubject('Herbstangebote')->forceFill(['unread' => false])->save();
$bySubject('Rechnung September')->forceFill(['status' => 'waiting', 'unread' => false])->save();
$bySubject('Danke für den Workshop')->forceFill(['status' => 'closed', 'unread' => false])->save();
$bySubject('Probenplan Oktober')->forceFill(['snoozed_until' => Carbon::now()->addDays(3)->setTime(8, 0), 'unread' => false])->save();

// A reply to Max that failed at the SMTP server: stored with its error.
$max = $bySubject('Stimmbildung');
Message::create([
    'mailbox_id' => $coaching->id,
    'conversation_id' => $max->id,
    'brand_id' => $max->brand_id,
    'direction' => Message::OUT,
    'message_id' => 'inbox.playground-failed@goldner.test',
    'from_email' => 'adrian@goldner.test',
    'from_name' => 'Adrian Goldner',
    'to' => [['email' => 'max@chorwerk.example', 'name' => 'Max Mustermann']],
    'subject' => 'Re: Stimmbildung für unseren Chor',
    'text' => "Hallo Herr Mustermann,\n\ngern! Der 14. November ginge bei mir. Ich schicke Ihnen dazu ein Angebot.\n\nViele Grüße\nAdrian Goldner\n",
    'body_stripped' => "Hallo Herr Mustermann,\n\ngern! Der 14. November ginge bei mir. Ich schicke Ihnen dazu ein Angebot.\n\nViele Grüße\nAdrian Goldner",
    'sent_at' => Carbon::parse('2026-09-25 09:12:00'),
    'send_error' => '535 5.7.8 Authentication failed: app password rejected',
]);
$max->forceFill(['last_message_at' => Carbon::parse('2026-09-25 09:12:00')])->save();

// Two messages the fetch gave up on.
foreach ([41, 42] as $uid) {
    FetchFailure::create([
        'mailbox_id' => $coaching->id,
        'brand_id' => $coaching->brand_id,
        'folder' => 'INBOX',
        'uid' => $uid,
        'attempts' => 3,
        'error' => 'Malformed MIME boundary',
        'first_seen_at' => Carbon::now()->subHours(3),
        'last_seen_at' => Carbon::now()->subHour(),
        'gave_up_at' => Carbon::now()->subHour(),
    ]);
}

// The choir mailbox cannot be reached at all.
$chor->forceFill([
    'last_error' => 'IMAP login failed: [AUTHENTICATIONFAILED] Invalid credentials (Failure)',
    'last_error_scope' => 'mailbox',
    'last_fetched_at' => Carbon::now()->subMinutes(2),
])->save();

// ── One template for the reply form ──────────────────────────────────────
$manager = 'Goldnead\\EmailTemplates\\Services\\EmailTemplateCollectionManager';
$data = 'Goldnead\\EmailTemplates\\Support\\EmailTemplateData';
if (class_exists($manager) && class_exists($data)) {
    app($manager)->ensure();
    app($manager)->upsert(new $data(
        slug: 'terminvorschlag',
        title: 'Terminvorschlag',
        subject: 'Termin',
        body: '<p>{{ contact.salutation }},</p><p>danke für deine Nachricht. Ich hätte folgende Termine frei:</p><p>Dienstag, 18 Uhr<br>Donnerstag, 10 Uhr</p><p>Liebe Grüße<br>Adrian</p>',
    ));
}

echo 'conversations: '.Conversation::count().', messages: '.Message::count().', attachments: '.Attachment::count()."\n";
