<?php

namespace Goldnead\StatamicInbox;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicInbox\Support\Settings;
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
        'input' => ['resources/js/cp.js'],
    ];

    /**
     * `inbox::…` rather than the package name core would derive.
     */
    protected $viewNamespace = 'inbox';

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/inbox.php', 'inbox');
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // In boot and not later: brand-context applies the overrides in an
        // `app->booted()` callback, and a registration after that point would
        // never reach config() in this process.
        $this->app->make(SettingsRegistry::class)->register(Settings::class);

        $this->registerPermissions();
    }

    protected function registerPermissions(): void
    {
        Permission::extend(function (): void {
            Permission::group('inbox', __('Inbox'), function (): void {
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
