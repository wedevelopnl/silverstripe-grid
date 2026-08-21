<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * What happens to the content on consuming pages when a block is deleted from
 * the library.
 *
 * The library refuses nothing: an author may always delete, but must say which
 * of the two outcomes they mean, because both are legitimate and neither can be
 * inferred from the request.
 */
enum SharedBlockDeleteMode: string
{
    /**
     * Remove the placements outright. The content disappears from every
     * consuming page, live included, without those pages being republished.
     */
    case Remove = 'remove';

    /**
     * Replace each placement with an independent copy of the block's content
     * first, so the pages keep rendering what they render today and only the
     * sharing is dissolved.
     */
    case Unshare = 'unshare';
}
