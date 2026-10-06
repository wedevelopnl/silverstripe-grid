<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Contract;

/**
 * Marks an element class declared only as a stand-in while the optional
 * dependency it needs is not installed ({@see \WeDevelop\Grid\UserForms\UserFormElement}).
 * The type picker, the create endpoint and the Grid elements report skip it.
 */
interface UnavailableElementInterface
{
}
