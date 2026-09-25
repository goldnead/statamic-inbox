<?php

use Goldnead\StatamicInbox\Parsing\QuoteStripper;
use Goldnead\StatamicInbox\Support\Subject;

it('cuts an answer to one of our replies at our own marker', function () {
    $text = "Super, danke!\n\nAnna\n\nAm Fr., 25. Sept. 2026 um 14:00 Uhr schrieb Adrian Goldner <adrian@goldner.test>:\n"
        ."> Hallo Anna,\n>\n> bis Dienstag!\n>\n> ".QuoteStripper::MARKER."\n>\n> Am 21.09.2026 08:15 schrieb Anna:\n> > Dienstag passt super";

    expect((new QuoteStripper)->strip($text))->toBe("Super, danke!\n\nAnna");
});

it('leaves a message without a quote alone', function () {
    expect((new QuoteStripper)->strip("Hallo,\n\nnur eine Frage.\n"))->toBe("Hallo,\n\nnur eine Frage.");
});

it('does not cut at a sentence that merely starts with "Am"', function () {
    $text = "Am Dienstag kann ich nicht.\nAnna schrieb das schon: Mittwoch?";

    expect((new QuoteStripper)->strip($text))->toBe($text);
});

it('normalises reply and forward prefixes, stacked and in German', function (string $subject) {
    expect(Subject::normalize($subject))->toBe('frage zum coaching');
})->with([
    'Frage zum Coaching',
    'Re: Frage zum Coaching',
    'AW: Frage zum Coaching',
    'Re: AW: Fwd: Frage zum Coaching',
    'WG:  Frage   zum Coaching',
    'RE[2]: Frage zum Coaching',
]);

it('writes a single Re: prefix', function () {
    expect(Subject::reply('AW: Re: Frage zum Coaching'))->toBe('Re: Frage zum Coaching');
});
