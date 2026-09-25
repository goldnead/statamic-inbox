<?php

use Goldnead\StatamicInbox\Support\Settings;

/*
 * JSON translations are global. "Inbox" translated as "Postfach-Einstellungen"
 * named the settings entry, and with it the addon in Statamic's addon list.
 * The entry gets its own title now (brand-context `settingsTitle()`), the
 * addon keeps its name.
 */
it('does not translate its own name into a settings label', function (): void {
    $own = json_decode((string) file_get_contents(__DIR__.'/../../lang/de.json'), true);
    $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

    expect($own)->not->toHaveKey('Inbox')
        ->and(array_keys(array_filter($own, fn (string $value, string $key): bool => isset($core[$key]) && $core[$key] !== $value, ARRAY_FILTER_USE_BOTH)))->toBe([]);

    app()->setLocale('de');
    expect(Settings::settingsTitle())->toBe('Postfach-Einstellungen');
});
