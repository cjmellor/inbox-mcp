<?php

namespace App\Imap;

enum SpecialFolder: string
{
    case Drafts = '\\Drafts';
    case Sent = '\\Sent';
    case Trash = '\\Trash';
    case Junk = '\\Junk';

    /**
     * iCloud flags only Trash and Sent with special-use attributes.
     */
    public function fallbackName(): string
    {
        return match ($this) {
            self::Drafts => 'Drafts',
            self::Sent => 'Sent Messages',
            self::Trash => 'Deleted Messages',
            self::Junk => 'Junk',
        };
    }
}
