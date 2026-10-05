<div class="media-element">
    <% if $ShowTitle && $Title %><$TitleTag<% if $TitleSizeClass %> class="$TitleSizeClass"<% end_if %>>$Title</$TitleTag><% end_if %>

    <% if $HasMedia %>
        <% include WeDevelop/Grid/Includes/MediaBlock %>
    <% end_if %>
</div>
