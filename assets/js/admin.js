(function($) {
    'use strict';

    $(document).on('click', '#s3mo-test-connection', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#s3mo-test-result');

        $btn.prop('disabled', true).text('Testing...');
        $result.html('').removeClass('notice notice-success notice-error');

        $.ajax({
            url: s3moAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 's3mo_test_connection',
                _ajax_nonce: s3moAdmin.nonce
            },
            success: function(response) {
                var isSuccess = response.success;
                var message = isSuccess
                    ? response.data.message
                    : (response.data && response.data.message ? response.data.message : 'Unknown error');
                $result
                    .addClass('notice ' + (isSuccess ? 'notice-success' : 'notice-error'))
                    .html('<p>' + message + '</p>');
            },
            error: function() {
                $result
                    .addClass('notice notice-error')
                    .html('<p>Request failed. Check your network connection.</p>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Test Connection');
            }
        });
    });

    // Toggle S3 detail popup in Media Library list view.
    $(document).on('click', '.s3mo-status-toggle', function(e) {
        e.preventDefault();
        var $details = $(this).siblings('.s3mo-details');
        $details.toggleClass('is-visible');
    });

    // Refresh stats dashboard via AJAX.
    $(document).on('click', '#s3mo-refresh-stats', function(e) {
        e.preventDefault();
        var $btn = $(this);

        $btn.prop('disabled', true).text('Refreshing...');

        $.ajax({
            url: s3moAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 's3mo_refresh_stats',
                _ajax_nonce: s3moAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#s3mo-stat-total-files').text(response.data.total_files);
                    $('#s3mo-stat-total-size').text(response.data.total_size);
                    $('#s3mo-stat-pending').text(response.data.pending);
                    $('#s3mo-stat-last-offloaded').text(response.data.last_offloaded);
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Refresh Stats');
            }
        });
    });
})(jQuery);
