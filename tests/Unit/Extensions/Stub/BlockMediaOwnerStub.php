<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Extensions\Stub;

use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * Lightweight stub for a DataObject that owns BlockMediaExtension.
 *
 * Extends DataObject for instanceof checks in Extension but bypasses the
 * constructor to avoid framework dependencies. Properties are set directly.
 */
class BlockMediaOwnerStub extends DataObject
{
    public ?string $MediaType = null;

    public ?string $MediaPosition = null;

    public ?string $VerticalAlignment = null;

    public ?string $MediaRatio = null;

    public ?int $ContentColumns = null;

    public ?int $GapSize = null;

    public ?string $VideoURL = null;

    public GridAdapterInterface $gridAdapter;

    private Image $mediaImage;

    /**
     * @param array<string, mixed>|int|null $record
     */
    public function __construct(mixed $record = null, $creationType = self::CREATE_OBJECT, $queryParams = [])
    {
        // Intentionally empty — bypass DataObject constructor
    }

    public function setMediaImage(Image $image): void
    {
        $this->mediaImage = $image;
    }

    /**
     * Simulates the has_one accessor for MediaImage.
     */
    public function MediaImage(): Image
    {
        return $this->mediaImage;
    }
}
