<style>
  .column-width-picker {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .column-width-picker__option {
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    border: 2px solid #dee2e6;
    border-radius: 4px;
    padding: 8px 12px;
    transition: border-color 0.15s, background-color 0.15s;
    min-width: 80px;
  }
  .column-width-picker__option:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
  }
  .column-width-picker__option:has(:checked) {
    border-color: #0d6efd;
    background-color: #e7f1ff;
  }
  .column-width-picker__option--disabled {
    opacity: 0.5;
    pointer-events: none;
  }
  .column-width-picker__image {
    margin-bottom: 4px;
  }
  .column-width-picker__diagram {
    display: flex;
    width: 100%;
    height: 24px;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 4px;
    min-width: 60px;
  }
  .column-width-picker__bar--content {
    background-color: #0d6efd;
    height: 100%;
  }
  .column-width-picker__bar--media {
    background-color: #6c757d;
    height: 100%;
  }
  .column-width-picker__label {
    font-size: 11px;
    font-weight: 600;
    color: #495057;
    white-space: nowrap;
  }
  .column-width-picker input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
</style>

<div class="column-width-picker" id="$ID" $AttributesHTML>
  <% loop $PickerOptions %>
    <label class="column-width-picker__option<% if $isDisabled %> column-width-picker__option--disabled<% end_if %>">
      <input
        type="radio"
        id="$ID"
        name="$Name"
        value="$Value"
        <% if $isChecked %> checked<% end_if %>
        <% if $isDisabled %> disabled<% end_if %>
        <% if $Up.Required %> required<% end_if %>
      />
      <% if $ImageURL %>
        <img class="column-width-picker__image" src="$ImageURL" alt="$Title" />
      <% else %>
        <div class="column-width-picker__diagram">
          <div class="column-width-picker__bar--content" style="width: $ContentPercent%"></div>
          <div class="column-width-picker__bar--media" style="width: $MediaPercent%"></div>
        </div>
      <% end_if %>
      <% if $IsFullWidth %>
        <span class="column-width-picker__label">$Title</span>
      <% else %>
        <span class="column-width-picker__label">$ContentColumns/$MediaColumns</span>
      <% end_if %>
    </label>
  <% end_loop %>
</div>
