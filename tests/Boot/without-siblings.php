<?php

/*
 * Fetches and replies the way a site without LeadHub and without the
 * suppression list does: those namespaces are taken out of the autoloader,
 * which is exactly what an install without them sees. Run in its own PHP
 * process by WithoutSiblingsTest, because the suite itself has both loaded.
 *
 * Prints one line of JSON on success; a fatal error ends the process with
 * its message.
 */

use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\ServiceProvider;
use Goldnead\StatamicInbox\Tests\Fakes\ArrayTransportFactory;
use Goldnead\StatamicInbox\Tests\Fakes\FakeMailboxClientFactory;
use Orchestra\Testbench\Foundation\Application;

$loader = require __DIR__.'/../../vendor/autoload.php';

$loader->setPsr4('Goldnead\\Leadhub\\', []);
$loader->setPsr4('Goldnead\\Suppression\\', []);

foreach ([
    'Goldnead\\Leadhub\\Facades\\LeadHub',
    'Goldnead\\Leadhub\\Support\\SourceEvent',
    'Goldnead\\Suppression\\Models\\Suppression',
    'Goldnead\\EmailTemplates\\Facades\\EmailTemplates',
    'Goldnead\\Activity\\Producers\\ProducerRegistry',
    'Goldnead\\StatamicAutomations\\Automations',
] as $sibling) {
    if (class_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is installed, this check proves nothing\n");
        exit(2);
    }
}

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
$app['config']->set('database.default', 'testing');
$app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['config']->set('queue.default', 'sync');

$app->register(Goldnead\BrandContext\ServiceProvider::class);
$app->register(ServiceProvider::class);

$app->make('migration.repository')->createRepository();
$app->make('migrator')->run([
    __DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations',
    __DIR__.'/../../database/migrations',
]);

$imap = new FakeMailboxClientFactory;
$smtp = new ArrayTransportFactory;
$app->instance(MailboxClientFactory::class, $imap);
$app->instance(TransportFactory::class, $smtp);

$mailbox = Mailbox::create([
    'name' => 'Adrian', 'email' => 'adrian@goldner.test',
    'imap_host' => 'imap.migadu.com', 'username' => 'adrian@goldner.test', 'password' => 'secret',
    'smtp_host' => 'smtp.migadu.com', 'sent_folder' => 'Sent', 'active' => true,
]);

$imap->client($mailbox)->deliver('INBOX', file_get_contents(__DIR__.'/../Fixtures/mail/01-new-thread.eml'));
$app->make(MailboxFetcher::class)->fetch($mailbox);

$out = $app->make(ReplySender::class)->send(Conversation::sole(), 'Hallo Anna');

echo json_encode([
    'messages' => Message::count(),
    'contact_id' => Conversation::sole()->contact_id,
    'sent' => $smtp->transport->messages()->count(),
    'out' => $out->direction,
]), "\n";
