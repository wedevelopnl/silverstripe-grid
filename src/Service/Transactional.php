<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use RuntimeException;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Value\Result;

/**
 * Runs a Result-returning operation inside a DB transaction, rolling back when
 * the operation reports a domain failure.
 *
 * The {@see Result} pattern never throws for expected validation failures, so a
 * plain {@see DatabaseConnector::withTransaction()} would commit every write the
 * operation made before returning an err Result. This helper bridges the two
 * worlds: it inspects the returned Result and throws a private sentinel to abort
 * the transaction on failure, then swallows that sentinel so the caller still
 * receives the original err Result (never the exception).
 *
 * Any other {@see RuntimeException} raised inside the operation propagates
 * unchanged — only the sentinel is intercepted.
 */
final class Transactional
{
    /** Sentinel message used to trigger a rollback without surfacing as an error. */
    private const string ROLLBACK_SIGNAL = 'Transactional.rollback-on-err';

    /**
     * Execute $operation atomically: commit when it returns an ok Result, roll
     * back when it returns an err Result. The original Result is always returned.
     *
     * When no database connection is available (e.g. early boot), the operation
     * runs directly without transaction wrapping.
     *
     * @template T
     * @param callable(): Result<T> $operation
     * @return Result<T>
     */
    public static function run(callable $operation): Result
    {
        $conn = DB::get_conn();
        if ($conn === null) {
            return $operation();
        }

        /** @var Result<T>|null $captured */
        $captured = null;

        try {
            $conn->withTransaction(function () use (&$captured, $operation): void {
                $captured = $operation();
                if ($captured->isErr()) {
                    throw new RuntimeException(self::ROLLBACK_SIGNAL);
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::ROLLBACK_SIGNAL) {
                throw $e;
            }
        }

        /** @var Result<T> $captured Guaranteed populated — the closure always assigns before the sentinel throw. */
        return $captured;
    }
}
