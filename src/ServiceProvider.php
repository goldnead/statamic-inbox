<?php

namespace Goldnead\StatamicInbox;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicInbox\Console\Commands\FetchMailboxes;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Events\InboxMessageSent;
use Goldnead\StatamicInbox\Imap\ImapEngineClientFactory;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Integrations\SuiteBridges;
use Goldnead\StatamicInbox\Mail\SmtpTransportFactory;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Support\HostGuard;
use Goldnead\StatamicInbox\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\Translator;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Control Panel bundle. Statamic 6 reads it from this property only;
     * the three values must byte-match `laravel()` in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    /**
     * `inbox::…` rather than the package name core would derive.
     */
    protected $viewNamespace = 'inbox';

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/inbox.php', 'inbox');

        // One guard per process, so a test (or a host) can swap the resolver.
        $this->app->singleton(HostGuard::class);

        // The real adapters. A site or a test binding its own wins.
        $this->app->bindIf(MailboxClientFactory::class, ImapEngineClientFactory::class);
        $this->app->bindIf(TransportFactory::class, SmtpTransportFactory::class);

        $this->app->singleton(SuiteBridges::class);

        if ($this->app->runningInConsole()) {
            // By hand: core's command discovery runs after Statamic's boot
            // sequence, which a plain console context never reaches.
            $this->commands([FetchMailboxes::class]);
        }
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerSchedule();

        // Activity and automations register their hooks once everything has
        // booted; SuiteBridges guards against Statamic's repeated `booted`.
        $this->app->booted(fn () => $this->app->make(SuiteBridges::class)->register());
    }

    public function bootAddon(): void
    {
        // bootAddon() may be reached twice (core's booted callback, a test
        // bed); listeners registered twice would write every timeline entry
        // through two calls. The dedupe key would catch it, the log would not.
        if ($this->app->bound('inbox.booted')) {
            return;
        }

        $this->app->instance('inbox.booted', true);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Statamic loads an addon's group files from lang/, not its JSON
        // dictionary; the Control Panel strings live in lang/de.json.
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // Laravel memoises a locale's merged JSON on the first lookup. In a
        // Statamic install something has always translated before this point,
        // so that memo lacks this file and the column labels built in PHP
        // would stay English. Dropping it re-reads everything lazily
        // (the same fix as in statamic-automations).
        if ($this->app->resolved('translator') && ($translator = $this->app->make('translator')) instanceof Translator) {
            $translator->setLoaded([]);
        }

        // In boot and not later: brand-context applies the overrides in an
        // `app->booted()` callback, and a registration after that point would
        // never reach config() in this process.
        $this->app->make(SettingsRegistry::class)->register(Settings::class);

        $this->registerPermissions();
        $this->registerListeners();
        $this->registerNavigation();
    }

    /**
     * "Postfach" under Tools, with the unread count. Statamic's nav has no
     * badge, so the count is in the label and, for the CP script, in
     * `data-inbox-unread`. Built per request, when the nav is.
     */
    public function registerNavigation(): void
    {
        Nav::extend(function ($nav): void {
            $unread = $this->unreadCount();

            // `mail-inbox-content`: core's set has no `mail-inbox`, and an
            // unknown name renders an empty square without a warning.
            $nav->create($unread > 0 ? __('Postfach').' ('.$unread.')' : __('Postfach'))
                ->section('Tools')
                ->route('inbox.index')
                ->icon('mail-inbox-content')
                ->can('view inbox')
                // No children on purpose: an item with explicit children is
                // only active on its exact URL, and every conversation and
                // mailbox screen would lose its breadcrumb. The mailboxes are
                // one button away in the listing header.
                ->attributes(['data-inbox-unread' => $unread]);
        });
    }

    /** Never throws: a nav that 500s takes the whole CP with it. */
    protected function unreadCount(): int
    {
        try {
            return Schema::hasTable('inbox_conversations')
                ? Conversation::query()->where('unread', true)->count()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Every minute, never overlapping itself. The command also locks each
     * mailbox on its own, so one slow server holds up nothing else.
     *
     * callAfterResolving, not app->booted(): booted callbacks fire more than
     * once in a Statamic app, and a schedule entry registered twice runs twice.
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inbox:fetch')
                ->everyMinute()
                ->withoutOverlapping(15)
                ->name('inbox-fetch');
        });
    }

    /**
     * Outside src/Listeners on purpose: core would discover that folder and
     * register them a second time.
     */
    protected function registerListeners(): void
    {
        $contacts = $this->app->make(LeadHubContacts::class);

        if (! $contacts->available()) {
            return;
        }

        $contacts->registerPanel();

        Event::listen(InboxMessageReceived::class, [LeadHubContacts::class, 'onReceived']);
        Event::listen(InboxMessageSent::class, [LeadHubContacts::class, 'onSent']);
    }

    protected function registerPermissions(): void
    {
        Permission::extend(function (): void {
            // `Postfach`, not `Inbox`: `Inbox` is the addon's name, which
            // brand-context shows as the settings entry, translated to
            // "Postfach-Einstellungen" so the nav does not list "Postfach" twice.
            Permission::group('inbox', __('Postfach'), function (): void {
                Permission::register('view inbox', function ($permission): void {
                    $permission->label(__('View conversations'))->children([
                        Permission::make('reply inbox')->label(__('Reply to conversations')),
                    ]);
                });

                Permission::register('manage inbox mailboxes')->label(__('Manage mailboxes'));
            });
        });
    }
}
