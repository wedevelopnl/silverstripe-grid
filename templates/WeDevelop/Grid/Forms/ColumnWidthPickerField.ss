<div class="ssgrid-column-width-picker" id="$ID" $AttributesHTML>
  <% loop $PickerOptions %>
    <label class="ssgrid-column-width-picker-option">
      <input
        type="radio"
        id="{$Up.ID}_{$Value}"
        name="$Up.Name"
        value="$Value"
        <% if $isChecked %> checked<% end_if %>
        <% if $Up.isDisabled %> disabled<% end_if %>
        <% if $Up.Required %> required<% end_if %>
      />
      <% if $ImageURL %>
        <img class="ssgrid-column-width-picker-image" src="$ImageURL" alt="$Title" />
      <% else %>
        <%-- Media bar first, mirroring the PNG diagrams and MediaPosition::First. --%>
        <div class="ssgrid-column-width-picker-diagram">
          <div class="ssgrid-column-width-picker-bar-media" style="width: $MediaPercent%"></div>
          <div class="ssgrid-column-width-picker-bar-content" style="width: $ContentPercent%"></div>
        </div>
      <% end_if %>
      <span class="ssgrid-column-width-picker-label">$Title</span>
    </label>
  <% end_loop %>
</div>
