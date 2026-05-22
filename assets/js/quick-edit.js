jQuery(function ($) {
    const $qe = inlineEditPost.edit;

    inlineEditPost.edit = function (id) {
        $qe.apply(this, arguments);
        let postId = 0;

        if (typeof (id) === 'object') {
            postId = parseInt(this.getId(id));
        }

        if (postId > 0) {
            const editRow = $('#edit-' + postId);
            const postRow = $('#post-' + postId);

            const start = postRow.find('.column-shift_start').text().trim();
            const end = postRow.find('.column-shift_end').text().trim();
            const min = postRow.find('.column-min_volunteers').text().trim();
            const max = postRow.find('.column-max_volunteers').text().trim();

            editRow.find('input.shift_start_field').val(start);
            editRow.find('input.shift_end_field').val(end);
            editRow.find('input.min_volunteers_field').val(min);
            editRow.find('input.max_volunteers_field').val(max);
        }
    };
});
