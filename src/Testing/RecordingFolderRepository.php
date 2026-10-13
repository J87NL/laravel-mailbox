<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolderRepository;

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

    public function create(string $path): FolderInterface
    {
        $folder = new RecordingFolder($path);

        $this->recordingMailbox->addFolder($folder);
        $this->folders[] = $folder;

        return $folder;
    }
}
