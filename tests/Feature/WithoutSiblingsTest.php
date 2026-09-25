<?php

/*
 * LeadHub and the suppression list are optional. Without them a mailbox is
 * still fetched and a reply still goes out; there is simply no contact to
 * link and no list to ask. Run in a separate process, see the script.
 */

use Symfony\Component\Process\Process;

it('fetches and replies with leadhub and suppression absent', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../Boot/without-siblings.php']);
    $process->setTimeout(60)->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

    $result = json_decode(trim($process->getOutput()), true);

    expect($result)->toBe([
        'messages' => 2,
        'contact_id' => null,
        'sent' => 1,
        'out' => 'out',
    ]);
});
