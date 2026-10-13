<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Support\Str;
use DirectoryTree\ImapEngine\Testing\FakeFolderRepository;
use Illuminate\Support\ItemNotFoundException;

class RecordingFolderRepository extends FakeFolderRepository
{
    /**
     * @param  array<int, FolderInterface>  $folders
     */
    public function __construct(
        private readonly RecordingMailbox $recordingMailbox,
        array $folders = [],
    ) {
        parent::__construct($recordingMailbox, $folders);
    }

    public function find(string $path): ?FolderInterface
    {
        return $this->get()->first(fn (FolderInterface $folder): bool => strtolower($folder->path()) === strtolower(Str::toImapUtf7($path)));
    }

    public function findOrFail(string $path): FolderInterface
    {
        return $this->find($path) ?? throw new ItemNotFoundException(sprintf('Folder [%s] not found.', $path));
    }

    public function create(string $path): FolderInterface
    {
        $folder = new RecordingFolder(Str::toImapUtf7($path));

        $this->recordingMailbox->addFolder($folder);
        $this->folders[] = $folder;

        return $folder;
    }
}
