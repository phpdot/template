<?php

declare(strict_types=1);

namespace PHPdot\Template\Exception;

use Throwable;

/**
 * Thrown when a template contains a syntax error.
 */
final class TemplateSyntaxException extends TemplateException
{
    public function __construct(
        public readonly string $template,
        public readonly int $templateLine,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
