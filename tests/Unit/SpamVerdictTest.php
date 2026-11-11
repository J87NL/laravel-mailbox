<?php

declare(strict_types=1);

use J87NL\Mailbox\Contracts\ClassifiesSpam;
use J87NL\Mailbox\Spam\NullSpamClassifier;
use J87NL\Mailbox\Spam\SpamVerdict;

it('refuses a score outside zero to one', function (float $score): void {
    new SpamVerdict($score);
})->throws(InvalidArgumentException::class)->with([
    -0.01,
    1.01,
]);

it('gives no verdict by default', function (): void {
    $classifier = app(ClassifiesSpam::class);

    expect($classifier)->toBeInstanceOf(NullSpamClassifier::class)
        ->and($classifier->classify(storedMessage(folder(mailbox()), 1))->hasScore())->toBeFalse();
});
