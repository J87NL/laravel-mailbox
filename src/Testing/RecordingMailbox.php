<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use LogicException;

class RecordingMailbox extends FakeMailbox
{
    public function folders(): FolderRepositoryInterface
    {
        return new RecordingFolderRepository($this, $this->folders);
    }

    public function addFolder(RecordingFolder $folder): void
    {
        $folder->setMailbox($this);

        $this->folders[] = $folder;
    }

    public function folder(string $path): RecordingFolder
    {
        $folder = $this->folders()->findOrFail($path);

        if (! $folder instanceof RecordingFolder) {
            throw new LogicException(sprintf('Folder [%s] is not a recording folder.', $path));
        }

        return $folder;
    }
}
