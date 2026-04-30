<?php

declare(strict_types=1);

namespace PHPdot\Template\Exception;

use Throwable;

/**
 * Thrown when an error occurs during template rendering.
 */
final class TemplateRenderException extends TemplateException
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
