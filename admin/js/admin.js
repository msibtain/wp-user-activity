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
        
        // Debug: Log the export request
        console.log('Exporting logs with filters:', {
            activity_type: $('#activity_type').val(),
            user_id: $('#user_id').val(),
            user_role: $('#user_role').val(),
            date_from: $('#date_from').val(),
            date_to: $('#date_to').val(),
            search: $('#search').val(),
            ajax_url: wpual_ajax.ajax_url
        });
        
        // Create form and submit
        var form = $('<form>', {
            'method': 'POST',
            'action': wpual_ajax.ajax_url,
            'target': '_blank',
            'style': 'display: none;',
            'id': 'wpual-export-form'
        });
        
        // Add form fields
        form.append($('<input>', {
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
            'name': 'user_role',
            'value': $('#user_role').val()
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
        
        // Remove any existing export form
        $('#wpual-export-form').remove();
        
        // Append form to body and submit
        $('body').append(form);
        form[0].submit();
        
        // Remove form after submission
        setTimeout(function() {
            form.remove();
        }, 1000);
        
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
    
    // Export active users functionality
    $('#export-active-users').on('click', function(e) {
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
            'value': 'wpual_export_active_users'
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'nonce',
            'value': wpual_ajax.nonce
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'user_role',
            'value': $('#user_role').val()
        })).append($('<input>', {
            'type': 'hidden',
            'name': 'activity_period',
            'value': $('#activity_period').val()
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
    // $('[title]').tooltip({
    //     position: { my: 'left+5 center', at: 'right center' }
    // });
    
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
    
    // User search functionality
    var userSearchTimeout;
    var userSearchResults = [];
    
    $('#user_search').on('keyup', function() {
        console.log('User search keyup');
        var searchTerm = $(this).val().trim();
        var $results = $('#user_search_results');
        var $container = $('.user-search-container');
        
        clearTimeout(userSearchTimeout);
        
        if (searchTerm.length < 2) {
            $results.hide().empty();
            return;
        }
        
        userSearchTimeout = setTimeout(function() {
            $.ajax({
                url: wpual_ajax.ajax_url,
                type: 'GET',
                data: {
                    action: 'wpual_search_users',
                    nonce: wpual_ajax.nonce,
                    search: searchTerm
                },
                success: function(response) {
                    userSearchResults = response;
                    displayUserResults(response);
                },
                error: function() {
                    $results.html('<div class="user-result-item error">Error loading users</div>').show();
                }
            });
        }, 300);
    });
    
    function displayUserResults(users) {
        var $results = $('#user_search_results');
        
        if (users.length === 0) {
            $results.html('<div class="user-result-item no-results">No users found</div>').show();
            return;
        }
        
        var html = '';
        users.forEach(function(user) {
            html += '<div class="user-result-item" data-user-id="' + user.id + '" data-user-text="' + user.text + '">';
            html += '<div class="user-name">' + user.display_name + '</div>';
            html += '<div class="user-email">' + user.email + '</div>';
            html += '</div>';
        });
        
        $results.html(html).show();
    }
    
    // Handle user selection
    $(document).on('click', '.user-result-item', function() {
        var userId = $(this).data('user-id');
        var userText = $(this).data('user-text');
        var userName = $(this).find('.user-name').text();
        var userEmail = $(this).find('.user-email').text();
        
        $('#user_id').val(userId);
        $('#user_search').val('').hide();
        $('#user_search_results').hide();
        
        var selectedUserHtml = '<div class="selected-user" id="selected_user_display">';
        selectedUserHtml += '<span>' + userName + ' (' + userEmail + ')</span>';
        selectedUserHtml += '<button type="button" class="remove-user" id="remove_user">&times;</button>';
        selectedUserHtml += '</div>';
        
        $('.user-search-container').append(selectedUserHtml);
    });
    
    // Handle user removal
    $(document).on('click', '.remove-user', function() {
        $('#user_id').val('');
        $('#selected_user_display').remove();
        $('#user_search').show().val('').focus();
    });
    
    // Hide results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.user-search-container').length) {
            $('#user_search_results').hide();
        }
    });
    
    // Show search input when clicking on container if no user is selected
    $('.user-search-container').on('click', function() {
        if (!$('#user_id').val()) {
            $('#user_search').show().focus();
        }
    });
    
    // Initialize: show search input if no user is selected
    if (!$('#user_id').val()) {
        $('#user_search').show();
    }
    
    // Keyboard navigation for search results
    $('#user_search').on('keydown', function(e) {
        var $results = $('#user_search_results');
        var $items = $results.find('.user-result-item');
        var currentIndex = $items.index($results.find('.user-result-item.highlighted'));
        
        switch(e.keyCode) {
            case 38: // Up arrow
                e.preventDefault();
                $items.removeClass('highlighted');
                if (currentIndex > 0) {
                    $items.eq(currentIndex - 1).addClass('highlighted');
                } else {
                    $items.last().addClass('highlighted');
                }
                break;
            case 40: // Down arrow
                e.preventDefault();
                $items.removeClass('highlighted');
                if (currentIndex < $items.length - 1) {
                    $items.eq(currentIndex + 1).addClass('highlighted');
                } else {
                    $items.first().addClass('highlighted');
                }
                break;
            case 13: // Enter
                e.preventDefault();
                var $highlighted = $results.find('.user-result-item.highlighted');
                if ($highlighted.length) {
                    $highlighted.click();
                }
                break;
            case 27: // Escape
                $results.hide();
                break;
        }
    });
    
    // Highlight first result on hover
    $(document).on('mouseenter', '.user-result-item', function() {
        $('#user_search_results .user-result-item').removeClass('highlighted');
        $(this).addClass('highlighted');
    });
    
}); 