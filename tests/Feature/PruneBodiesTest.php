<?php

declare(strict_types=1);

use J87NL\Mailbox\Actions\FetchMessageBody;
use J87NL\Mailbox\Models\MailboxMessage;

it('forgets bodies fetched longer ago than the configured days', function (?string $option, array $expected): void {
    config()->set('mailbox.cache.body_days', 30);
    $folder = folder(mailbox());
    storedMessage($folder, 1, ['attachments' => [], 'body_fetched_at' => now()->subDays(40), 'body_html' => '<p>old</p>']);
    storedMessage($folder, 2, ['body_fetched_at' => now()->subDays(10), 'body_html' => '<p>recent</p>']);
    storedMessage($folder, 3);

    $this->artisan('mailbox:prune-bodies', array_filter(['--days' => $option]))->assertSuccessful();

    expect(MailboxMessage::query()
        ->orderBy('uid')
        ->get()
        ->map(fn (MailboxMessage $message): bool => $message->hasBody())
        ->all())->toBe($expected);
})->with([
    'config default' => [null, [false, true, false]],
    'option' => ['5', [false, false, false]],
]);

it('does nothing when pruning is switched off', function (): void {
    config()->set('mailbox.cache.body_days', null);
    storedMessage(folder(mailbox()), 1, ['body_fetched_at' => now()->subYears(2), 'body_html' => '<p>old</p>']);

    $this->artisan('mailbox:prune-bodies')->assertSuccessful();

    expect(MailboxMessage::query()
        ->sole()
        ->hasBody())->toBeTrue();
});

it('fetches a pruned body again when the message is opened', function (): void {
    $mailbox = mailbox();
    $message = storedMessage(folder($mailbox), 1)->load('folder');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(FetchMessageBody::class)->handle($message);

    expect($message->refresh()->body_text)->toBe('Plain body')
        ->and($message->hasBody())->toBeTrue();
});
