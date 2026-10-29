// Join a Copy address button to each address box meant to be copied: the
// server's, and a new key's, which is shown only once. Browsers allow copying
// only on HTTPS or localhost, so elsewhere the address is selected instead,
// with focus moved to it, ready to copy with the keyboard. A status below,
// which screen readers announce, says which happened.
$(function () {
    $('input.mcp-server-copy-source').each(function () {
        var source = $(this);
        var button = $('<button type="button">')
            .addClass('mcp-server-copy')
            .text(Omeka.jsTranslate('Copy address'));
        var status = $('<span role="status">').addClass('mcp-server-copy-status');
        var timer;
        source.wrap($('<div>').addClass('mcp-server-copy-group')).after(button);
        source.parent().after(status);
        var report = function (message) {
            clearTimeout(timer);
            status.text(Omeka.jsTranslate(message));
            timer = setTimeout(function () {
                status.text('');
            }, 8000);
        };
        var select = function () {
            source.trigger('focus').trigger('select');
            report('Selected. Copy it with your keyboard.');
        };
        button.on('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(source.val()).then(function () {
                    report('Copied');
                }, select);
            } else {
                select();
            }
        });
    });
});
