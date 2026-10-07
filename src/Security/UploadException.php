<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Upload abgelehnt; die Meldung ist fuer Benutzer bestimmt.
 */
final class UploadException extends RuntimeException
{
}
