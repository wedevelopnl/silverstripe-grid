<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

/**
 * Adds /{page}/grid-form/{id} to every page: the route a form element posts to.
 * Applied only when userforms is installed (_config/userforms.yml).
 *
 * @extends Extension<ContentController>
 */
class UserFormRouteExtension extends Extension
{
    /** @var array<string, string> */
    private static array $url_handlers = [
        'grid-form/$ID!' => 'handleGridForm',
    ];

    /** @var list<string> */
    private static array $allowed_actions = ['handleGridForm'];

    public function handleGridForm(): UserFormElementController
    {
        $owner = $this->getOwner();
        $page = $owner->data();
        // `$ID!` is a presence check only — validate the segment here.
        $id = filter_var($owner->getRequest()->param('ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $element = $page instanceof SiteTree && $id !== false
            ? Injector::inst()->get(UserFormElementResolver::class)->resolve($page, $id)
            : null;

        if ($element === null) {
            $owner->httpError(404);
        }

        return UserFormElementController::create($page, $element);
    }
}
