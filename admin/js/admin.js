/**
 * WP User Activity Logger Admin JavaScript
 */

jQuery(document).ready(function($) {
    
    // Export logs functionality
    $('#export-logs').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        // Show loading state
        $button.text('Exporting...').prop('disabled', true);
        
        // Create form and submit
        var form = $('<form>', {
            'method': 'POST',
            'action': wpual_ajax.ajax_url
        }).append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'wpual_export_logs'
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'nonce',
            'value': wpual_ajax.nonce
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'activity_type',
            'value': $('#activity_type').val()
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'user_id',
            'value': $('#user_id').val()
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'date_from',
            'value': $('#date_from').val()
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'date_to',
            'value': $('#date_to').val()
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'search',
            'value': $('#search').val()
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
        
        // Reset button after a short delay
        setTimeout(function() {
            $button.text(originalText).prop('disabled', false);
        }, 2000);
    });
    
    // Clear logs functionality
    $('#clear-logs').on('click', function(e) {
        e.preventDefault();
        
        if (confirm(wpual_ajax.confirm_clear)) {
            var $button = $(this);
            var originalText = $button.text();
            
            // Show loading state
            $button.text('Clearing...').prop('disabled', true);
            $('.wrap').addClass('wpual-loading');
            
            $.post(wpual_ajax.ajax_url, {
                action: 'wpual_clear_logs',
                nonce: wpual_ajax.nonce
            }, function(response) {
                if (response.success) {
                    showNotice(response.data, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data, 'error');
                }
            }).fail(function() {
                showNotice('An error occurred while clearing logs.', 'error');
            }).always(function() {
                $button.text(originalText).prop('disabled', false);
                $('.wrap').removeClass('wpual-loading');
            });
        }
    });
    
    // Bulk actions functionality
    $('select[name="action"], select[name="action2"]').on('change', function() {
        var action = $(this).val();
        var $applyButton = $(this).closest('.tablenav').find('.button.action');
        
        if (action === 'delete') {
            $applyButton.addClass('button-primary');
        } else {
            $applyButton.removeClass('button-primary');
        }
    });
    
    // Form submission confirmation for bulk delete
    $('form').on('submit', function(e) {
        var $form = $(this);
        var action = $form.find('select[name="action"]').val() || $form.find('select[name="action2"]').val();
        var $checkedBoxes = $form.find('input[name="log_ids[]"]:checked');
        
        if (action === 'delete' && $checkedBoxes.length > 0) {
            if (!confirm('Are you sure you want to delete ' + $checkedBoxes.length + ' selected log(s)? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Select all functionality
    $('#cb-select-all-1').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('input[name="log_ids[]"]').prop('checked', isChecked);
    });
    
    // Individual checkbox change
    $('input[name="log_ids[]"]').on('change', function() {
        var totalCheckboxes = $('input[name="log_ids[]"]').length;
        var checkedCheckboxes = $('input[name="log_ids[]"]:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#cb-select-all-1').prop('indeterminate', false).prop('checked', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#cb-select-all-1').prop('indeterminate', false).prop('checked', true);
        } else {
            $('#cb-select-all-1').prop('indeterminate', true);
        }
    });
    
    // Filter form enhancements
    $('.wpual-filters form').on('submit', function() {
        var $form = $(this);
        var $submitButton = $form.find('input[type="submit"]');
        var originalText = $submitButton.val();
        
        // Show loading state
        $submitButton.val('Filtering...').prop('disabled', true);
        
        // Reset after form submission
        setTimeout(function() {
            $submitButton.val(originalText).prop('disabled', false);
        }, 1000);
    });
    
    // Auto-submit filters on change (optional)
    $('.wpual-filters select').on('change', function() {
        // Uncomment the following line if you want filters to auto-submit
        // $(this).closest('form').submit();
    });
    
    // Date range validation
    $('#date_from, #date_to').on('change', function() {
        var dateFrom = $('#date_from').val();
        var dateTo = $('#date_to').val();
        
        if (dateFrom && dateTo && dateFrom > dateTo) {
            showNotice('Date "From" cannot be later than date "To".', 'error');
            $(this).val('');
        }
    });
    
    // Search input enhancement
    $('#search').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).closest('form').submit();
        }
    });
    
    // Activity type badges hover effect
    $('.activity-type').on('mouseenter', function() {
        $(this).css('transform', 'scale(1.05)');
    }).on('mouseleave', function() {
        $(this).css('transform', 'scale(1)');
    });
    
    // URL tooltip enhancement
    $('.column-url a').on('mouseenter', function() {
        var $link = $(this);
        var fullUrl = $link.attr('title');
        var displayUrl = $link.text();
        
        if (fullUrl && fullUrl !== displayUrl) {
            $link.attr('data-tooltip', fullUrl);
        }
    });
    
    // Responsive table improvements
    function handleResponsiveTable() {
        if ($(window).width() <= 782) {
            $('.wp-list-table').addClass('mobile-table');
        } else {
            $('.wp-list-table').removeClass('mobile-table');
        }
    }
    
    // Call on load and resize
    handleResponsiveTable();
    $(window).on('resize', handleResponsiveTable);
    
    // Show notice function
    function showNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Make dismissible
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + E for export
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 69) {
            e.preventDefault();
            $('#export-logs').click();
        }
        
        // Ctrl/Cmd + F for search focus
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 70) {
            e.preventDefault();
            $('#search').focus();
        }
    });
    
    // Performance optimization: Debounce search
    var searchTimeout;
    $('#search').on('input', function() {
        clearTimeout(searchTimeout);
        var $input = $(this);
        
        searchTimeout = setTimeout(function() {
            if ($input.val().length >= 3 || $input.val().length === 0) {
                $input.closest('form').submit();
            }
        }, 500);
    });
    
    // Initialize tooltips
    $('[title]').tooltip({
        position: { my: 'left+5 center', at: 'right center' }
    });
    
    // Table row highlighting
    $('.wp-list-table tbody tr').on('click', function(e) {
        if (!$(e.target).is('input[type="checkbox"], a, button')) {
            $(this).toggleClass('selected');
        }
    });
    
    // Double-click to view details (future enhancement)
    $('.wp-list-table tbody tr').on('dblclick', function() {
        // Could open a modal with detailed information
        console.log('Double-clicked row:', $(this).data());
    });
    
    // Export current filters with export
    $('#export-logs').on('click', function() {
        var currentFilters = $('.wpual-filters form').serialize();
        if (currentFilters) {
            // Store filters in sessionStorage for export
            sessionStorage.setItem('wpual_export_filters', currentFilters);
        }
    });
    
    // Clear session storage on page load
    $(window).on('beforeunload', function() {
        sessionStorage.removeItem('wpual_export_filters');
    });
}); 