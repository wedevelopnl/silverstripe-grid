<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

/**
 * Generates "copy" titles for duplicated elements.
 *
 * "My Block" → "My Block copy"
 * "My Block copy" → "My Block copy 2"
 * "My Block copy 2" → "My Block copy 3"
 */
final class TitleGenerator
{
    public static function generateCopyTitle(string $title): string
    {
        $hasCopyPattern = '/^.*(\scopy($|\s[0-9]+$))/';
        $hasNumPattern = '/^.*(\s[0-9]+$)/';

        if (preg_match($hasCopyPattern, $title, $parts) === 1) {
            $copy = $parts[1];

            if (preg_match($hasNumPattern, $copy, $numParts) === 1) {
                $num = trim($numParts[1]);
                $inc = (int) $num + 1;

                return substr($title, 0, -strlen($num)) . (string) $inc;
            }

            return $title . ' 2';
        }

        return $title . ' copy';
    }
}
