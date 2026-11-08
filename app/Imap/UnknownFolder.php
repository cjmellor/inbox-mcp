<?php

namespace App\Imap;

use InvalidArgumentException;

class UnknownFolder extends InvalidArgumentException
{
    public static function named(string $folder): self
    {
        return new self("Unknown folder [{$folder}]. Use list-folders to see the available folders.");
    }
}
