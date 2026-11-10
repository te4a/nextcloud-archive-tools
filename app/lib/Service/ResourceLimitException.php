<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

/** A fixed, translatable admission failure; never contains user data or counts. */
final class ResourceLimitException extends \RuntimeException {}
