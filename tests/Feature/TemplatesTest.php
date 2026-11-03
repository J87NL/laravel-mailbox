<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use J87NL\Mailbox\Actions\RenderTemplate;
use J87NL\Mailbox\Actions\SaveTemplate;
use J87NL\Mailbox\Models\MailboxContact;
use J87NL\Mailbox\Models\MailboxTemplate;

function template(array $attributes = []): MailboxTemplate
{
    return app(SaveTemplate::class)->handle(mailbox(), null, [
        'body' => '<p>Beste {voornaam},</p><p>{onderwerp}</p>',
        'name' => 'Bevestiging',
        'subject' => 'Re: {onderwerp}',
        ...$attributes,
    ]);
}

it('fills the Dutch and English placeholders with the recipient, subject, date, user and mailbox', function (): void {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $template = template([
        'body' => '<p>{voornaam} {naam} {first_name} {onderwerp} {datum} {mijn_naam} {mailbox_naam} {my_name}</p>',
    ]);

    $rendered = app(RenderTemplate::class)->handle($template, user(['name' => 'Johan Montenij']), 'piet@example.org', 'Piet de Vries', 'Offerte');

    expect($rendered)->toBe([
        'body' => '<p>Piet Piet de Vries Piet Offerte 03-11-2026 Johan Montenij Team Johan Montenij</p>',
        'subject' => 'Re: Offerte',
    ]);
});

it('leaves unknown placeholders and placeholders without a value as they are', function (): void {
    $template = template([
        'body' => '<p>Beste {voornaam}, {onbekend} {onderwerp}</p>',
    ]);

    $rendered = app(RenderTemplate::class)->handle($template, null);

    expect($rendered['body'])->toBe('<p>Beste {voornaam}, {onbekend} {onderwerp}</p>')
        ->and($rendered['subject'])->toBe('Re: {onderwerp}');
});

it('takes the name of the recipient from the address book when the caller does not know it', function (): void {
    $template = template();
    MailboxContact::query()
        ->create([
            'email' => 'piet@example.org',
            'mailbox_id' => $template->mailbox_id,
            'name' => 'Piet de Vries',
        ]);

    $rendered = app(RenderTemplate::class)->handle($template, null, ' Piet@Example.org ');

    expect($rendered['body'])->toStartWith('<p>Beste Piet,</p>');
});

it('escapes the filled values in the body but not in the subject', function (): void {
    $template = template();

    $rendered = app(RenderTemplate::class)->handle($template, null, null, '<b>Eve</b>', 'A & B');

    expect($rendered['body'])->toBe('<p>Beste &lt;b&gt;Eve&lt;/b&gt;,</p><p>A &amp; B</p>')
        ->and($rendered['subject'])->toBe('Re: A & B');
});

it('sanitizes the body and stores an empty subject as null', function (): void {
    $template = template([
        'body' => '<p onclick="alert(1)">Hoi {voornaam}</p><script>alert(1)</script>',
        'subject' => '',
    ]);

    expect($template->refresh()->body)->toBe('<p>Hoi {voornaam}</p>')
        ->and($template->subject)->toBeNull();
});

it('takes only the first name and ignores a name that is an address', function (): void {
    $template = template([
        'body' => '<p>{voornaam}</p>',
    ]);
    $render = app(RenderTemplate::class);

    expect($render->handle($template, null, null, 'Smith, John')['body'])->toBe('<p>John</p>')
        ->and($render->handle($template, null, 'piet@example.org', 'piet@example.org')['body'])->toBe('<p>{voornaam}</p>');
});
