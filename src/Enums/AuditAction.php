<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum AuditAction: string
{
    case AccessGranted = 'access_granted';
    case AccessRevoked = 'access_revoked';
    case Archived = 'archived';
    case Deleted = 'deleted';
    case FolderCreated = 'folder_created';
    case Flagged = 'flagged';
    case Linked = 'linked';
    case MailboxCreated = 'mailbox_created';
    case MailboxUpdated = 'mailbox_updated';
    case MarkedRead = 'marked_read';
    case MarkedUnread = 'marked_unread';
    case Moved = 'moved';
    case Sent = 'sent';
    case Trashed = 'trashed';
    case Unflagged = 'unflagged';
}
