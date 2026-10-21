<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Forms\FolderForm;

it('accepts a parent folder of the same mailbox', function (): void {
    $mailbox = mailbox();
    $parent = folder($mailbox);

    $data = (new FolderForm($mailbox, ''))->validate(Request::create('/', 'POST', [
        'name' => 'Projects',
        'parent_id' => $parent->id,
    ]));

    expect($data['parent_id'])->toBe($parent->id);
});

it('refuses a parent folder of another mailbox', function (): void {
    $mailbox = mailbox();
    folder($mailbox);
    $foreign = folder(mailbox(['name' => 'Other']));

    expect(fn () => (new FolderForm($mailbox, ''))->validate(Request::create('/', 'POST', [
        'name' => 'Projects',
        'parent_id' => $foreign->id,
    ])))->toThrow(ValidationException::class);
});
