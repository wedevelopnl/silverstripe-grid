<div class="ssgrid-toolbar-label">
    <span class="$Icon ssgrid-toolbar-icon" aria-hidden="true"></span>
    <% if $Link %>
        <a href="$Link" target="_blank" rel="noopener" class="ssgrid-toolbar-link">$Title</a>
    <% else %>
        <span class="ssgrid-toolbar-title">$Title</span>
    <% end_if %>
    <% if $Kind == 'shared' %>
        <span class="ssgrid-toolbar-tag"><%t WeDevelop\Grid\AdminToolbar\GridMenu.SHARED 'Shared' %></span>
    <% end_if %>
    <% if $Span %>
        <span class="ssgrid-toolbar-meta">
            <span aria-hidden="true">$Span/$Of</span>
            <span class="ssat:sr-only"><%t WeDevelop\Grid\AdminToolbar\GridMenu.SPAN '{span} of {of} columns' span=$Span of=$Of %></span>
            <% if $Hidden %><%t WeDevelop\Grid\AdminToolbar\GridMenu.HIDDEN 'hidden' %><% end_if %>
        </span>
    <% end_if %>
</div>
