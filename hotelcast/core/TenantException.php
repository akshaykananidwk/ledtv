<?php
declare(strict_types=1);

/** Thrown on a missing hotel context or a cross-hotel access attempt (CLI). */
final class TenantException extends RuntimeException
{
}
