// Cut each tool's description that runs past two lines, with a button that
// shows the rest. The cut is only visual, so screen readers get all of it.
$(function () {
    $('.mcp-server-description').each(function (index) {
        var text = $(this).addClass('is-cut');
        if (this.scrollHeight <= this.clientHeight + 1) {
            text.removeClass('is-cut');
            return;
        }
        var id = 'mcp-server-description-' + index;
        var button = $('<button type="button">')
            .addClass('mcp-server-description-toggle')
            .attr({'aria-expanded': 'false', 'aria-controls': id})
            .text(Omeka.jsTranslate('Show more'));
        text.attr('id', id).after(button);
        button.on('click', function () {
            var expanded = !text.toggleClass('is-cut').hasClass('is-cut');
            button.attr('aria-expanded', expanded ? 'true' : 'false')
                .text(Omeka.jsTranslate(expanded ? 'Show less' : 'Show more'));
        });
    });
});
