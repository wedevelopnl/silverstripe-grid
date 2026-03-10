<div id="$HolderID" class="field grid-settings-field $extraClass">
  <% if $Title %><label class="left">$Title</label><% end_if %>
  <div class="middleColumn">
    <% loop $ViewportData %>
      <fieldset class="grid-settings-field__viewport" data-viewport="$Key">
        <legend>$Label</legend>

        <% if not $IsFirst %>
          <label class="grid-settings-field__inherit">
            <input
              type="checkbox"
              name="{$FieldName}[$Key][inherit]"
              value="1"
              <% if $Inherit %>checked="checked"<% end_if %>
            />
            Inherit from previous viewport
          </label>
        <% end_if %>

        <div class="grid-settings-field__controls">
          <label class="grid-settings-field__control">
            Width
            <select name="{$FieldName}[$Key][width]">
              <% loop $WidthOptions %>
                <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
              <% end_loop %>
            </select>
          </label>

          <label class="grid-settings-field__control">
            Offset
            <select name="{$FieldName}[$Key][offset]">
              <% loop $OffsetOptions %>
                <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
              <% end_loop %>
            </select>
          </label>

          <label class="grid-settings-field__control grid-settings-field__control--checkbox">
            <input
              type="checkbox"
              name="{$FieldName}[$Key][visible]"
              value="1"
              <% if $Visible %>checked="checked"<% end_if %>
            />
            Visible
          </label>
        </div>
      </fieldset>
    <% end_loop %>
  </div>
</div>
