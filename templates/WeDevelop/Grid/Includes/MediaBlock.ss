<% if $MediaType == 'image' && $MediaImage %>
    <figure class="media-block media-block--image">
        <% if $MediaRatioClass %><div class="$MediaRatioClass"><% end_if %>
            <img
                src="$MediaImageSourceURL"
                width="$MediaImageWidth"
                height="$MediaImageHeight"
                alt="<% if $MediaCaption %>$MediaCaption.ATT<% else %>$Title.ATT<% end_if %>"
                loading="lazy"
            />
        <% if $MediaRatioClass %></div><% end_if %>
        <% if $MediaCaption %><figcaption>$MediaCaption</figcaption><% end_if %>
    </figure>
<% else_if $MediaType == 'video' && $VideoURL %>
    <div class="media-block media-block--video">
        <% if $MediaRatioClass %><div class="$MediaRatioClass"><% end_if %>
            <% if $VideoHasOverlay && $VideoCustomThumbnail %>
                <div class="media-block__overlay" data-video-url="$VideoURL.ATT">
                    <img
                        src="$VideoCustomThumbnail.ScaleWidth(1200).URL"
                        alt="<% if $MediaCaption %>$MediaCaption.ATT<% else %>$Title.ATT<% end_if %>"
                        loading="lazy"
                    />
                </div>
            <% else_if $VideoHasOverlay && $VideoEmbedThumbnail %>
                <div class="media-block__overlay" data-video-url="$VideoURL.ATT">
                    <img
                        src="$VideoEmbedThumbnail.ATT"
                        alt="<% if $MediaCaption %>$MediaCaption.ATT<% else %>$Title.ATT<% end_if %>"
                        loading="lazy"
                    />
                </div>
            <% else %>
                <iframe
                    src="$VideoEmbedURL.ATT"
                    title="<% if $MediaCaption %>$MediaCaption.ATT<% else %>$Title.ATT<% end_if %>"
                    allowfullscreen
                    loading="lazy"
                ></iframe>
            <% end_if %>
        <% if $MediaRatioClass %></div><% end_if %>
        <% if $MediaCaption %><p class="media-block__caption">$MediaCaption</p><% end_if %>
    </div>
<% end_if %>
