<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Publication state of a grid element derived from SilverStripe's status flags.
 *
 * The model collapses `getStatusFlags()` output — a map keyed by SS core flag
 * names with label payloads — into a single enum for UI display. Priority
 * follows the CMS's own conceptual order: a record removed from draft wins
 * over an added-to-draft, which wins over a modified-since-publish, with
 * published as the default when no flags are set.
 */
enum ElementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Modified = 'modified';
    case Removed = 'removed';

    /**
     * Derive the element's status from SilverStripe's `getStatusFlags()` output.
     *
     * Unknown flag keys are ignored — projects needing custom statuses must
     * extend this enum rather than relying on silent pass-through.
     *
     * @param array<string, array{text: string, title: string}> $flags
     */
    public static function fromStatusFlags(array $flags): self
    {
        return match (true) {
            isset($flags['removedfromdraft']) => self::Removed,
            isset($flags['addedtodraft']) => self::Draft,
            isset($flags['modified']) => self::Modified,
            default => self::Published,
        };
    }
}
