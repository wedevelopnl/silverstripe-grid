<div>
    <button type="button" class="ssat:btn" data-toggle-dialog="$DialogId">
        <span class="ssat:h-3.5 $Icon" aria-hidden="true"></span>
        <span class="ssat:max-lg:hidden ssat:ms-2 ssat:me-1">$Title</span>
    </button>
    <dialog id="$DialogId" aria-labelledby="$DialogId-title" class="ssat:w-6/12 ssat:bg-transparent">
        <div class="dialog-inner ssat:relative ssat:bg-white ssat:p-6 ssat:rounded-lg">
            <% include WeDevelop\AdminToolbar\Includes\DialogHeader Title=$Title %>
            <% loop $Zones %>
                <section data-grid-zone="$Name" class="ssgrid-toolbar-zone">
                    <% if $ShowHeading %><h4 class="ssgrid-toolbar-zone-heading"><%t WeDevelop\Grid\AdminToolbar\GridMenu.ZONE 'Zone: {name}' name=$Name %></h4><% end_if %>
                    <% loop $Nodes %><% include WeDevelop\Grid\AdminToolbar\Includes\GridNode %><% end_loop %>
                </section>
            <% end_loop %>
        </div>
    </dialog>
</div>
