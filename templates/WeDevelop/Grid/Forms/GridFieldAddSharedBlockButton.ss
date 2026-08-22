<div class="btn-group" data-shared-block-add data-testid="add-shared-block">
    $PrimaryButton
    <button
        type="button"
        class="btn btn-primary dropdown-toggle dropdown-toggle-split"
        aria-haspopup="menu"
        aria-expanded="false"
        data-shared-block-add-trigger
        data-testid="add-shared-block-menu-trigger"
    >
        <%-- The caret is `.dropdown-toggle`'s own ::after triangle, so this half
             renders no glyph of its own and needs a text alternative. --%>
        <span class="visually-hidden">$MoreLabel</span>
    </button>
    <%-- Start-aligned (Bootstrap's default): the control leads the toolbar, so
         the menu grows right into empty space. Right-aligning it would run the
         wider rows left, under the CMS sidebar. `data-bs-popper="static"` is
         what unlocks the menu's CSS placement without Popper — the same
         attribute the admin's own reactstrap dropdowns emit. --%>
    <div
        class="dropdown-menu"
        data-bs-popper="static"
        role="menu"
        tabindex="-1"
        data-shared-block-add-menu
        data-testid="add-shared-block-menu-dropdown"
    >
        <% loop $ShapeItems %>$Button<% end_loop %>
        <% if $ElementItems %>
            <div class="dropdown-divider"></div>
            <h6 class="dropdown-header">$ElementsHeader</h6>
            <% loop $ElementItems %>$Button<% end_loop %>
        <% end_if %>
    </div>
</div>
