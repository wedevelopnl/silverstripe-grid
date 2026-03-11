<div class="content-element">
    <% if $ShowTitle && $Title %><$TitleTag<% if $TitleSizeClass %> class="$TitleSizeClass"<% end_if %>>$Title</$TitleTag><% end_if %>

    <% if $IsLayoutMode %>
        <div class="$LayoutRowClasses">
            <div class="$MediaColumnClasses">
                <% include WeDevelop/Grid/Includes/MediaBlock %>
            </div>
            <div class="$ContentColumnClasses">
                <div class="$ContentPaddingClasses">
                    <% if $HTML %>$HTML<% end_if %>
                </div>
            </div>
        </div>
    <% else %>
        <% if $HasMedia %>
            <% include WeDevelop/Grid/Includes/MediaBlock %>
        <% end_if %>
        <% if $HTML %>$HTML<% end_if %>
    <% end_if %>
</div>
