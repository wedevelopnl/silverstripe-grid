<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use NoDiscard;
use Closure;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;

/**
 * Translates SilverStripe ValidationExceptions into Result failures.
 *
 * This is the single boundary where framework exceptions become domain Results.
 */
final class WriteResult
{
    /**
     * Execute an operation, catching ValidationExceptions as Result failures.
     *
     * @template T
     * @param Closure():T $operation
     * @return Result<T>
     */
    #[NoDiscard('The Result reports whether the write succeeded; discarding it silently swallows write/validation failures.')]
    public static function from(Closure $operation): Result
    {
        try {
            return Result::ok($operation());
        } catch (ValidationException $validationException) {
            return Result::fail(...self::translateValidationException($validationException));
        }
    }

    /**
     * @return non-empty-list<ValidationError>
     */
    private static function translateValidationException(ValidationException $e): array
    {
        /** @var array<array{message: non-empty-string, fieldName: string}> $messages */
        $messages = $e->getResult()->getMessages();

        // Log the raw exception server-side for observability. The messages are
        // still returned to the client because our domain validators (hierarchy,
        // ownership) compose the translated, user-facing text the CMS relies on
        // (see the error-surfacing fix in api/client.ts); the server log is where
        // the full framework detail lives without widening what the client sees.
        Injector::inst()->get(LoggerInterface::class)->debug(
            'WriteResult caught a ValidationException: {message}',
            ['message' => $e->getMessage()],
        );

        if ($messages === []) {
            return [new ValidationError(message: 'Validation failed.')];
        }

        $errors = [];

        foreach ($messages as $msg) {
            $errors[] = new ValidationError(
                message: $msg['message'],
                field: $msg['fieldName'] !== '' ? $msg['fieldName'] : null,
            );
        }

        /** @var non-empty-list<ValidationError> $errors Guaranteed non-empty: $messages is non-empty */
        return $errors;
    }
}
