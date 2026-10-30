// Cut each tool's or prompt's description that runs past two lines, with a
// button that shows the rest. The cut is only visual, so screen readers get all
// of it.
$(function () {
    var descriptions = $('.mcp-server-description').map(function (index) {
        var text = $(this);
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
        return {text: text, button: button};
    }).get();

    // Cut what runs past two lines, and show its button. Measured again once
    // the page has loaded and when the window is resized, since the tables'
    // layout settles after this script runs. A description the user expanded
    // stays as it is.
    var measure = function () {
        descriptions.forEach(function (description) {
            if ('true' === description.button.attr('aria-expanded')) {
                return;
            }
            var element = description.text.addClass('is-cut')[0];
            var long = element.scrollHeight > element.clientHeight + 1;
            description.text.toggleClass('is-cut', long);
            description.button.prop('hidden', !long);
        });
    };
    measure();
    $(window).on('load resize', measure);
});
