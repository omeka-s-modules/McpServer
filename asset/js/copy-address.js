// Add a Copy address button to each address box. Browsers copy only on HTTPS
// or localhost, so elsewhere it selects the address and says to copy it with
// the keyboard.
$(function () {
    var label = Omeka.jsTranslate('Copy address');
    var copied = Omeka.jsTranslate('Copied');
    var selected = Omeka.jsTranslate('Selected. Copy it with your keyboard.');
    $('input.mcp-server-copy-source').each(function () {
        var source = $(this);
        var button = $('<button type="button" class="mcp-server-copy">').text(label);
        var status = $('<span role="status" class="mcp-server-copy-status">');
        var timer;
        source.wrap('<div class="mcp-server-copy-group"></div>').after(button);
        source.parent().after(status);
        var restore = function () {
            button.css('min-width', '').text(label);
        };
        var select = function () {
            restore();
            source.trigger('focus').trigger('select');
            status.removeClass('sr-only').text(selected);
        };
        button.on('click', function () {
            clearTimeout(timer);
            if (!navigator.clipboard || !window.isSecureContext) {
                select();
                return;
            }
            navigator.clipboard.writeText(source.val()).then(function () {
                // Keep the button's width, so the shorter text doesn't
                // shrink it.
                button.css('min-width', button.css('width')).text(copied);
                status.addClass('sr-only').text(copied);
                timer = setTimeout(function () {
                    restore();
                    status.text('');
                }, 2000);
            }, select);
        });
    });
});
