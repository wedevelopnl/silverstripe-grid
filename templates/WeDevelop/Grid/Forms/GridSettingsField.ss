<div id="$HolderID" class="field grid-settings-field $extraClass">
  <% if $Title %><label class="left">$Title</label><% end_if %>
  <div class="middleColumn">
    <style>
      .grid-settings-field__overrides {
        width: 100%;
        font-size: 13px;
      }
      .grid-settings-field__overrides th {
        font-weight: 600;
        padding: 6px 8px;
        border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.5px;
        color: #6c757d;
      }
      .grid-settings-field__overrides td {
        padding: 6px 8px;
        vertical-align: middle;
        border-bottom: 1px solid #eee;
      }
      .grid-settings-field__overrides tr:not(.is-overridden):not(.is-default) td:nth-child(n+3) {
        opacity: 0.5;
      }
      .grid-settings-field__overrides tr.is-default {
        background: #f8f9fa;
      }
      .grid-settings-field__overrides .badge {
        background: #0d6efd;
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 3px;
        vertical-align: middle;
        text-transform: uppercase;
        letter-spacing: 0.3px;
      }
      .grid-settings-field__overrides select {
        width: 100%;
        min-width: 60px;
      }
    </style>

    <table class="table grid-settings-field__overrides">
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
            <tr class="is-default is-overridden">
              <td>$Label <span class="badge">default</span></td>
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
            <tr class="<% if $Override %>is-overridden<% end_if %>">
              <td>$Label</td>
              <td>
                <input
                  type="checkbox"
                  name="{$FieldName}[$Key][override]"
                  value="1"
                  class="grid-settings-field__override-toggle"
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
