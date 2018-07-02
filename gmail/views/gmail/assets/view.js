var SpamViewScript;
SpamViewScript = function ($) {
    var settings = {
        pjaxViewId: ''
    };

    function onClick() {
        var pjax = $('#' + settings.pjaxViewId);
        pjax.on('click', '.remove-from-spam', function (e) {
            e.preventDefault();
            var ids = [];
            $.each(pjax.find('.select-checkbox'), function (e, item) {
                if ($(item).is(':checked') && $(item).data('id') !== '') {
                    ids.push($(item).data('id'));
                }
            });
            if (ids.length > 0) {
                $.ajax({
                    type: 'POST',
                    dataType: 'json',
                    url: $(this).attr('href'),
                    data: {"emailIds": ids},
                    success: function () {
                        $.pjax.reload({container: pjax, "timeout": 10000});
                    },
                    error: function () {
                        alert('При обновлении произошла ошибка');
                    }
                });
            } else {
                alert('Проверьте выбранные данные.');
            }
        });
    }

    return {
        init: function (options) {
            settings = options;
            onClick();
        }
    };
}(jQuery);
