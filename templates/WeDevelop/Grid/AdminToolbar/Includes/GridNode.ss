<% if $Kind == 'row' %>
    <div data-grid-node="row" class="ssgrid-toolbar-row" style="grid-template-columns: repeat($Of, minmax(0, 1fr));">
        <% loop $Children %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
    </div>
<% else_if $Kind == 'column' %>
    <div data-grid-node="column" class="ssgrid-toolbar-column" style="grid-column: span $Span / span $Span;">
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
        <% loop $Children %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
    </div>
<% else_if $Kind == 'element' %>
    <div data-grid-node="element" class="ssgrid-toolbar-element">
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
    </div>
<% else %>
    <div data-grid-node="$Kind" class="ssgrid-toolbar-box"<% if $Span %> style="grid-column: span $Span / span $Span;"<% end_if %>>
        <% include WeDevelop\Grid\AdminToolbar\Includes\GridNodeLabel %>
        <% loop $Children %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
    </div>
<% end_if %>
