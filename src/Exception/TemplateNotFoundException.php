<?php

declare(strict_types=1);

namespace PHPdot\Template\Exception;

use Throwable;

/**
 * Thrown when a template cannot be resolved by any loader.
 */
final class TemplateNotFoundException extends TemplateException
{
    public function __construct(
        public readonly string $template,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
