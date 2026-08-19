<div id="$HolderID" class="field ssgrid-grid-settings-field $extraClass">
  <% if $Title %><label class="left">$Title</label><% end_if %>
  <div class="middleColumn">
    <table class="table ssgrid-grid-settings-field-overrides">
      <thead>
        <tr>
          <th>Viewport</th>
          <th>Override</th>
          <th>Width</th>
          <th>Offset</th>
          <th>Visible</th>
        </tr>
      </thead>
      <tbody>
        <% loop $ViewportData %>
          <% if $IsDefault %>
            <tr data-default data-overridden>
              <td>$Label <span class="ssgrid-grid-settings-field-badge">default</span></td>
              <td></td>
              <td>
                <select name="{$FieldName}[$Key][width]">
                  <% loop $WidthOptions %>
                    <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
                  <% end_loop %>
                </select>
              </td>
              <td>
                <select name="{$FieldName}[$Key][offset]">
                  <% loop $OffsetOptions %>
                    <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
                  <% end_loop %>
                </select>
              </td>
              <td>
                <input
                  type="checkbox"
                  name="{$FieldName}[$Key][visible]"
                  value="1"
                  <% if $Visible %>checked="checked"<% end_if %>
                />
              </td>
            </tr>
          <% else %>
            <tr<% if $Override %> data-overridden<% end_if %>>
              <td>$Label</td>
              <td>
                <input
                  type="checkbox"
                  name="{$FieldName}[$Key][override]"
                  value="1"
                  class="ssgrid-grid-settings-field-override-toggle"
                  <% if $Override %>checked="checked"<% end_if %>
                />
              </td>
              <td>
                <select name="{$FieldName}[$Key][width]" <% if not $Override %>disabled="disabled"<% end_if %>>
                  <% loop $WidthOptions %>
                    <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
                  <% end_loop %>
                </select>
              </td>
              <td>
                <select name="{$FieldName}[$Key][offset]" <% if not $Override %>disabled="disabled"<% end_if %>>
                  <% loop $OffsetOptions %>
                    <option value="$Value" <% if $Selected %>selected="selected"<% end_if %>>$Label</option>
                  <% end_loop %>
                </select>
              </td>
              <td>
                <input
                  type="checkbox"
                  name="{$FieldName}[$Key][visible]"
                  value="1"
                  <% if $Visible %>checked="checked"<% end_if %>
                  <% if not $Override %>disabled="disabled"<% end_if %>
                />
              </td>
            </tr>
          <% end_if %>
        <% end_loop %>
      </tbody>
    </table>
  </div>
</div>
