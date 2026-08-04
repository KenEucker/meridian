<?php

namespace App\Services\Waiver;

use InvalidArgumentException;

class WaiverDocumentException extends InvalidArgumentException
{
    public static function incompleteReference(): self
    {
        return new self('A document-backed waiver names both a document type and a document.');
    }

    public static function unsupportedDocumentType(): self
    {
        return new self('A waiver may only reference a policy or procedure document.');
    }

    public static function unknownDocument(): self
    {
        return new self('The referenced document does not exist.');
    }

    public static function documentOutsideOrganization(): self
    {
        return new self('The referenced document must belong to the waiver organization.');
    }

    public static function documentNotPublished(): self
    {
        return new self('Only published documents may back a waiver.');
    }
}
