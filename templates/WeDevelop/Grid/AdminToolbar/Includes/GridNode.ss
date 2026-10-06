<% if $Kind == 'row' %>
    <div data-grid-node="row" class="ssgrid-toolbar-row">
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
        <div class="ssgrid-toolbar-tracks" style="--ssgrid-of: $Of; grid-template-columns: repeat($Of, minmax(0, 1fr));">
            <% loop $Children %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
        </div>
    </div>
<% else_if $Kind == 'element' %>
    <div data-grid-node="element" class="ssgrid-toolbar-element">
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
    </div>
<% else %>
    <div data-grid-node="$Kind" class="ssgrid-toolbar-box"<% if $Span %><% if $Hidden %> data-hidden<% end_if %> style="--ssgrid-offset: $Offset; grid-column: span $Track / span $Track;"<% end_if %>>
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
        <% loop $Children %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
    </div>
<% end_if %>
