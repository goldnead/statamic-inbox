<?php

namespace Goldnead\StatamicInbox\Tests;

use Goldnead\StatamicInbox\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\CP\Nav;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        // AddonTestCase swaps Nav for a strict mock; let bootAddon() extend it.
        Nav::shouldReceive('extend')->andReturnNull();

        // Statamic runs bootAddon() inside a booted callback Testbench never
        // fires; run it the way that callback would.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();
    }

    /**
     * Registered here, not by a `migrate` call in setUp(): Testbench runs this
     * before RefreshDatabase opens its transaction. DDL inside that transaction
     * commits it implicitly under MySQL.
     *
     * The siblings' migrations are listed by hand: the addon manifest names
     * only this package, so Statamic never boots theirs here. brand-context and
     * suppression load their own from a plain boot(), leadhub from its
     * provider, but listing them keeps the bed independent of that detail.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-leadhub/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-suppression/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\Suppression\ServiceProvider::class,
            \Goldnead\Leadhub\ServiceProvider::class,
        ];
    }

    /**
     * The CP routes as Statamic mounts them: inside core's authenticated CP
     * group, under /cp, named statamic.cp.*. Statamic pushes addon routes from
     * its booted callback, which runs after Testbench has loaded routes, so the
     * bed mounts the same file itself.
     */
    protected function defineRoutes($router): void
    {
        if (! file_exists(__DIR__.'/../routes/cp.php')) {
            return;
        }

        $router->middleware(['statamic.cp', 'statamic.cp.authenticated'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Neither UTC nor a zone the fixtures use: a date that forgets its
        // timezone must show up as a failure, not coincide with the answer.
        $app['config']->set('app.timezone', 'America/Chicago');
        $app['config']->set('brand-context.multi_brand', false);

        $app['config']->set('leadhub.storage.driver', 'eloquent');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'noreply@example.test', 'name' => 'Test']);
        $app['config']->set('filesystems.disks.local.root', sys_get_temp_dir().'/inbox-test-'.getmypid());
    }

    /**
     * In-memory SQLite by default; DB_DRIVER=mysql runs the identical suite
     * against a real server (phpunit.mysql.xml), which is the only place the
     * unique index the message dedupe rests on is really exercised.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'inbox_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
