<h1>$Title</h1>
<% if $UseGrid %>
    <% loop $Sections %>$Me<% end_loop %>
<% else %>
    $Content
<% end_if %>
$Form
