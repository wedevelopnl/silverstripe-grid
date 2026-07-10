<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Support;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\TestOnly;
use WeDevelop\Grid\Model\GridElement;

/**
 * TestOnly extension that fails write-time validation only once an element titled
 * {@see REJECTED_TITLE} has been reindexed to {@see REJECTED_SORT}.
 *
 * The title/sort pair matters: the element must be creatable at its original Sort
 * and only become invalid part-way through a reindex batch, so that earlier
 * siblings in the same batch have already been written when the failure hits.
 * That is the only way to observe whether
 * {@see \WeDevelop\Grid\Service\ElementPlacementService::persistAndReturn()}
 * wrapped the batch in a transaction.
 *
 * @extends Extension<GridElement>
 */
class RejectOnReindexExtension extends Extension implements TestOnly
{
    public const string REJECTED_TITLE = 'reject-once-reindexed';

    public const int REJECTED_SORT = 3;

    public function updateValidate(ValidationResult $result): void
    {
        $owner = $this->getOwner();

        if ((string) $owner->Title === self::REJECTED_TITLE && (int) $owner->Sort === self::REJECTED_SORT) {
            $result->addError('Rejected by the test extension after reindexing.');
        }
    }
}
