/**
 * HL Core Frontend JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // === Cohort Tab Switching ===
        $('.hl-cohort-tab').on('click', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $wrap = $(this).closest('.hl-frontend-wrap');

            // Update active tab
            $wrap.find('.hl-cohort-tab').removeClass('active');
            $(this).addClass('active');

            // Show target content
            $wrap.find('.hl-cohort-content').removeClass('active');
            $wrap.find('#' + targetId).addClass('active');
        });

        // === Member Card Expand/Collapse ===
        $('.hl-member-card-header').on('click', function() {
            var $details = $(this).siblings('.hl-member-details');
            $details.toggleClass('open');
            $(this).toggleClass('expanded');
        });

        // === School Filter (Cohort Dashboard) ===
        $('.hl-school-filter').on('change', function() {
            var schoolId = $(this).val();
            var $table = $(this).closest('.hl-cohort-content, .hl-frontend-wrap').find('.hl-data-table');

            if (!schoolId) {
                $table.find('tbody tr').show();
            } else {
                $table.find('tbody tr').each(function() {
                    var rowSchool = $(this).data('school-id');
                    $(this).toggle(String(rowSchool) === String(schoolId));
                });
            }
        });

        // === Animate Progress Rings on Scroll ===
        var animated = false;
        function animateRings() {
            if (animated) return;
            $('.hl-progress-ring').each(function() {
                var $ring = $(this);
                if ($ring.is(':visible') && isInViewport($ring[0])) {
                    animated = true;
                    var $fill = $ring.find('.ring-fill');
                    var pct = parseFloat($fill.data('percent')) || 0;
                    var r = parseFloat($fill.attr('r'));
                    var circumference = 2 * Math.PI * r;
                    var offset = circumference - (pct / 100) * circumference;
                    $fill.css({
                        'stroke-dasharray': circumference,
                        'stroke-dashoffset': offset
                    });
                }
            });
        }

        function isInViewport(el) {
            var rect = el.getBoundingClientRect();
            return rect.top < window.innerHeight && rect.bottom > 0;
        }

        // Initialize progress rings immediately and on scroll
        animateRings();
        $(window).on('scroll', animateRings);

        // === Staff Table Search ===
        $(document).on('input', '.hl-search-input[data-table]', function() {
            var query  = $(this).val().toLowerCase();
            var tableId = $(this).data('table');
            var $table  = $('#' + tableId);

            $table.find('tbody tr').each(function() {
                var name = $(this).data('name') || '';
                $(this).toggle(name.indexOf(query) !== -1);
            });
        });

        // === Report Filters (school, team, name) ===
        $(document).on('input change', '.hl-report-filter, .hl-report-search', function() {
            var $container = $(this).closest('.hl-reports-container');
            var school = $container.find('[data-filter="school"]').val() || '';
            var team   = $container.find('[data-filter="team"]').val() || '';
            var name   = ($container.find('.hl-report-search').val() || '').toLowerCase();

            $container.find('.hl-report-row').each(function() {
                var $row = $(this);
                var match = true;

                if (school && $row.data('school') !== school) match = false;
                if (team && $row.data('team') !== team) match = false;
                if (name && ($row.data('name') || '').indexOf(name) === -1) match = false;

                $row.toggle(match);

                // Also hide the detail row if its parent is hidden
                var detailId = $row.next('.hl-detail-row').attr('id');
                if (!match && detailId) {
                    $('#' + detailId).hide();
                }
            });
        });

        // === Cycle Workspace Tab Switching ===
        $(document).on('click', '.hl-cw-tab', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $wrap = $(this).closest('.hl-cw-v2');

            $wrap.find('.hl-cw-tab').removeClass('active');
            $(this).addClass('active');

            $wrap.find('.hl-cw-panel').removeClass('active');
            $wrap.find('#' + targetId).addClass('active');
        });

        // === Detail Row Toggle (Reports Tab) ===
        $(document).on('click', '.hl-detail-toggle, .hl-cw-view-btn', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $detail = $('#' + targetId);
            $detail.toggle();
            $(this).text($detail.is(':visible') ? 'Hide' : 'View');
        });

        // === Topbar User Dropdown ===
        $(document).on('click', '#hl-topbar-user-btn', function(e) {
            e.stopPropagation();
            var $dropdown = $('#hl-topbar-dropdown');
            var isOpen = !$dropdown.prop('hidden');
            // Close tour dropdown if open.
            $('#hl-tour-dropdown').prop('hidden', true);
            $('#hl-tour-trigger').attr('aria-expanded', 'false');
            $dropdown.prop('hidden', isOpen);
            $(this).attr('aria-expanded', !isOpen);
        });
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#hl-topbar-user-wrap').length) {
                $('#hl-topbar-dropdown').prop('hidden', true);
                $('#hl-topbar-user-btn').attr('aria-expanded', 'false');
            }
            if (!$(e.target).closest('.hl-topbar__tour-wrap').length) {
                $('#hl-tour-dropdown').prop('hidden', true);
                $('#hl-tour-trigger').attr('aria-expanded', 'false');
            }
        });

        // === Tour Guide Dropdown ===
        $(document).on('click', '#hl-tour-trigger', function(e) {
            e.stopPropagation();
            var $dropdown = $('#hl-tour-dropdown');
            var isOpen = !$dropdown.prop('hidden');
            // Close user dropdown if open.
            $('#hl-topbar-dropdown').prop('hidden', true);
            $('#hl-topbar-user-btn').attr('aria-expanded', 'false');
            // Toggle tour dropdown.
            $dropdown.prop('hidden', isOpen);
            $(this).attr('aria-expanded', !isOpen);
        });

        // === Feature Tracker ===
        (function() {
            var $wrap = $('.hlft-wrapper');
            if (!$wrap.length) return;

            var nonce   = $wrap.data('nonce');
            var isAdmin = $wrap.data('is-admin') === 1 || $wrap.data('is-admin') === '1';
            var currentUuid = null; // UUID of currently viewed ticket in detail modal

            // ── Helpers ──

            function ajax(action, data, callback, errCallback) {
                data.action = action;
                data.nonce  = nonce;
                $.post(hlCoreAjax.ajaxurl, data, function(resp) {
                    if (resp.success) {
                        callback(resp.data);
                    } else {
                        showToast(resp.data || 'An error occurred.', true);
                        if (errCallback) errCallback();
                    }
                }).fail(function() {
                    showToast('Request failed. Please try again.', true);
                    if (errCallback) errCallback();
                });
            }

            function showToast(msg, isError) {
                var $t = $('#hlft-toast');
                $t.text(msg).css('background', isError ? 'var(--hl-error)' : 'var(--hl-primary)').fadeIn(200);
                setTimeout(function() { $t.fadeOut(300); }, 3000);
            }

            var typeLabels = { bug: 'Bug', improvement: 'Improvement', feature_request: 'Feature Request' };
            var typeIcons = { bug: '\uD83D\uDC1E', improvement: '\u2728', feature_request: '\uD83D\uDE80' }; // 🐞 ✨ 🚀
            var statusLabels = { open: 'Open', in_review: 'In Review', in_progress: 'In Progress', resolved: 'Resolved', closed: 'Closed' };

            var categoryLabels = {
                course_content: 'Course Content',
                platform_issue: 'Platform Issue',
                account_access: 'Account & Access',
                forms_assessments: 'Forms & Assessments',
                reports_data: 'Reports & Data',
                other: 'Other'
            };

            var userSearchXhr = null;
            var userSearchTimer = null;
            var userSearchResults = [];

            // Escape HTML entities for safe string interpolation.
            function esc(str) { return $('<span>').text(String(str == null ? '' : str)).html(); }

            // ── Load Tickets ──

            function loadTickets() {
                var $body = $('#hlft-table-body');
                var $loading = $('#hlft-table-loading');
                var $empty = $('#hlft-empty');
                var $noResults = $('#hlft-no-results');
                var $table = $('#hlft-table');
                var $indicator = $('#hlft-filter-indicator');

                $loading.show();
                $table.css('opacity', '0.5');
                $empty.hide();
                $noResults.hide();

                var statusVal = $('#hlft-filter-status').val();
                var typeVal = $('#hlft-filter-type').val();
                var priorityVal = $('#hlft-filter-priority').val();
                var searchVal = $('#hlft-search').val();

                // Show/hide filter indicator (default: "Closed tickets hidden")
                var hasFilters = typeVal || statusVal || priorityVal || (searchVal && searchVal.length >= 2);
                if (!hasFilters) {
                    $indicator.show();
                } else {
                    $indicator.hide();
                }

                ajax('hl_ticket_list', {
                    type: typeVal,
                    status: statusVal || '',
                    priority: priorityVal,
                    search: searchVal
                }, function(data) {
                    $loading.hide();
                    $table.css('opacity', '1');
                    $body.empty();

                    if (data.tickets.length === 0) {
                        $table.hide();
                        if (hasFilters) {
                            $noResults.show();
                        } else {
                            $empty.show();
                        }
                        return;
                    }

                    $table.show();
                    $.each(data.tickets, function(i, t) {
                        var row = '<tr data-uuid="' + esc(t.ticket_uuid) + '">' +
                            '<td class="hlft-td-type" title="' + esc(typeLabels[t.type] || '') + '">' + (typeIcons[t.type] || '') + '</td>' +
                            '<td><strong>#' + esc(t.ticket_id) + '</strong> ' + esc(t.title) + '</td>' +
                            '<td><span class="hlft-priority-badge hlft-priority-badge--' + esc(t.priority) + '">' + esc(t.priority) + '</span></td>' +
                            '<td><span class="hlft-submitter"><img class="hlft-avatar" src="' + esc(t.creator_avatar) + '" alt=""> ' + esc(t.creator_name) + '</span></td>' +
                            '<td><span class="hlft-status-pill hlft-status-pill--' + esc(t.status) + '">' + esc(statusLabels[t.status] || '') + '</span></td>' +
                            '<td>' + esc(t.time_ago) + '</td>' +
                            '</tr>';
                        $body.append(row);
                    });
                });
            }

            // ── Detail Modal ──

            function openDetail(uuid) {
                currentUuid = uuid;
                var $modal = $('#hlft-detail-modal');
                var $loading = $('#hlft-detail-loading');
                var $content = $('#hlft-detail-content');

                $modal.show();
                $loading.show();
                $content.hide();

                ajax('hl_ticket_get', { ticket_uuid: uuid }, function(t) {
                    $loading.hide();
                    $content.show();

                    // Header
                    $('#hlft-detail-type').attr('class', 'hlft-type-badge hlft-type-badge--' + t.type).text((typeIcons[t.type] || '') + ' ' + typeLabels[t.type]);
                    $('#hlft-detail-title').text('#' + t.ticket_id + ' ' + t.title);

                    // Meta
                    var meta = '<span class="hlft-priority-badge hlft-priority-badge--' + esc(t.priority) + '">' + esc(t.priority) + '</span>' +
                        ' <span class="hlft-status-pill hlft-status-pill--' + esc(t.status) + '">' + esc(statusLabels[t.status] || '') + '</span>' +
                        ' <span class="hlft-meta-category">' + esc(categoryLabels[t.category] || t.category || '') + '</span>' +
                        ' <span>By <img class="hlft-avatar" src="' + esc(t.creator_avatar) + '" alt=""> ' + esc(t.creator_name);

                    if (t.creator_department) {
                        meta += ' &bull; ' + esc(t.creator_department);
                    }

                    meta += ' &bull; ' + esc(t.time_ago) + '</span>';

                    if (t.context_mode === 'view_as' && t.context_user_name) {
                        meta += ' <span class="hlft-meta-context">';
                        if (t.context_user_url) {
                            meta += 'Viewing as <a href="' + esc(t.context_user_url) + '" target="_blank">' + esc(t.context_user_name) + '</a>';
                        } else {
                            meta += 'Viewing as ' + esc(t.context_user_name);
                        }
                        meta += '</span>';
                    }

                    $('#hlft-detail-meta').html(meta);

                    // Description (plain text in V1 — no rich text editor)
                    $('#hlft-detail-description').text(t.description);

                    // Ticket-level attachments
                    renderAttachments($('#hlft-detail-attachments'), t.attachments || []);

                    // Edit button
                    var $actions = $('#hlft-detail-actions');
                    $actions.empty();
                    if (t.can_edit) {
                        $actions.html('<button type="button" class="hl-btn hl-btn-small" id="hlft-edit-btn">Edit</button>');
                    }

                    // Status dropdown (admin only)
                    if (isAdmin) {
                        $('#hlft-status-select').val(t.status);
                    }

                    // Comments
                    renderComments(t.comments, t.comment_count);
                });
            }

            function renderComments(comments, count) {
                var $list = $('#hlft-comments-list');
                $list.empty();
                $('#hlft-comment-count').text(count);

                if (comments.length === 0) {
                    $list.html('<p class="hlft-comments-empty">No comments yet</p>');
                    return;
                }

                $.each(comments, function(i, c) {
                    var attachHtml = buildAttachmentsHtml(c.attachments || []);
                    var html = '<div class="hlft-comment">' +
                        '<img class="hlft-avatar" src="' + esc(c.user_avatar) + '" alt="">' +
                        '<div class="hlft-comment__body">' +
                        '<div class="hlft-comment__header"><span class="hlft-comment__name">' + esc(c.user_name) + '</span><span class="hlft-comment__time">' + esc(c.time_ago) + '</span></div>' +
                        '<div class="hlft-comment__text">' + esc(c.comment_text) + '</div>' +
                        attachHtml +
                        '</div></div>';
                    $list.append(html);
                });
            }

            // ── Attachment Helpers ──

            function buildAttachmentsHtml(attachments) {
                if (!attachments || !attachments.length) return '';
                var html = '<div class="hlft-attachments">';
                $.each(attachments, function(i, a) {
                    html += '<img class="hlft-attachment-thumb" src="' + esc(a.file_url) + '" alt="' + esc(a.file_name) + '" data-full="' + esc(a.file_url) + '">';
                });
                html += '</div>';
                return html;
            }

            function renderAttachments($container, attachments) {
                $container.empty();
                if (!attachments || !attachments.length) return;
                $container.html(buildAttachmentsHtml(attachments));
            }

            // Upload files and return array of attachment objects via callback
            function uploadFiles(files, ticketUuid, commentId, callback) {
                var results = [];
                var pending = files.length;
                if (!pending) { callback(results); return; }

                $.each(files, function(i, file) {
                    var fd = new FormData();
                    fd.append('action', 'hl_ticket_upload');
                    fd.append('nonce', nonce);
                    fd.append('ticket_uuid', ticketUuid);
                    fd.append('file', file);
                    if (commentId) fd.append('comment_id', commentId);

                    $.ajax({
                        url: hlCoreAjax.ajaxurl,
                        type: 'POST',
                        data: fd,
                        processData: false,
                        contentType: false,
                        success: function(resp) {
                            if (resp.success) results.push(resp.data);
                            pending--;
                            if (pending === 0) callback(results);
                        },
                        error: function() {
                            pending--;
                            if (pending === 0) callback(results);
                        }
                    });
                });
            }

            // Show file preview thumbnails
            function showFilePreview($container, files) {
                $container.empty();
                $.each(files, function(i, f) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        $container.append('<img src="' + e.target.result + '" alt="">');
                    };
                    reader.readAsDataURL(f);
                });
            }

            // Lightbox — click thumbnail to see full image
            $(document).on('click', '.hlft-attachment-thumb', function(e) {
                e.stopPropagation();
                var src = $(this).data('full') || $(this).attr('src');
                $('body').append('<div class="hlft-lightbox"><img src="' + esc(src) + '"></div>');
            });
            $(document).on('click', '.hlft-lightbox', function() {
                $(this).remove();
            });

            // ── Create / Edit Modal ──

            var pendingFormFiles = [];
            var pendingCommentFiles = [];

            function openCreateModal() {
                $('#hlft-form-title').text('New Ticket');
                $('#hlft-form-uuid').val('');
                $('#hlft-form-title-input').val('');
                $('#hlft-form-category').prop('selectedIndex', 0);
                $('#hlft-form-type').val('');
                $('#hlft-form-priority').val('medium');
                $('#hlft-form-description').val('');
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                pendingFormFiles = [];
                var dept = $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                $('#hlft-form-context-mode').val('self');
                $('#hlft-context-user-wrap').hide();
                $('#hlft-form-context-user-id').val('');
                $('#hlft-context-user-chip').hide().empty();
                $('#hlft-user-search-input').val('');
                $('#hlft-user-search-results').hide().empty();
                // Clear stale search state from prior modal sessions.
                userSearchResults = [];
                clearTimeout(userSearchTimer);
                if (userSearchXhr && userSearchXhr.readyState !== 4) { userSearchXhr.abort(); }
                $('#hlft-form-submit').text('Submit').prop('disabled', false);
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }

            function openEditModal(ticket) {
                $('#hlft-form-title').text('Edit Ticket');
                $('#hlft-form-uuid').val(ticket.ticket_uuid);
                $('#hlft-form-title-input').val(ticket.title);
                $('#hlft-form-category').val(ticket.category || '');
                $('#hlft-form-type').val(ticket.type);
                $('#hlft-form-priority').val(ticket.priority);
                $('#hlft-form-description').val(ticket.description);
                pendingFormFiles = [];
                $('#hlft-form-file').val('');
                $('#hlft-form-preview').empty();
                var dept = ticket.creator_department || $wrap.data('user-department') || 'Not assigned';
                var deptClass = dept === 'Not assigned' ? ' hlft-dept-empty' : '';
                $('#hlft-form-department').attr('class', 'hlft-dept-readonly' + deptClass).text(dept);
                $('#hlft-form-context-mode').val(ticket.context_mode || 'self');
                if (ticket.context_mode === 'view_as' && ticket.context_user_name) {
                    $('#hlft-context-user-wrap').show();
                    $('#hlft-form-context-user-id').val(ticket.context_user_id);
                    var chipHtml = '';
                    if (ticket.context_user_avatar) {
                        chipHtml += '<img class="hlft-avatar" src="' + esc(ticket.context_user_avatar) + '" alt=""> ';
                    }
                    chipHtml += esc(ticket.context_user_name) +
                        ' <button type="button" class="hlft-chip-remove" title="Remove">&times;</button>';
                    $('#hlft-context-user-chip').show().html(chipHtml);
                    $('#hlft-user-search-input').val('').hide();
                } else {
                    $('#hlft-context-user-wrap').hide();
                    $('#hlft-form-context-user-id').val('');
                    $('#hlft-context-user-chip').hide().empty();
                    $('#hlft-user-search-input').val('').show();
                }
                // Clear stale search state from prior modal sessions.
                $('#hlft-user-search-results').hide().empty();
                userSearchResults = [];
                clearTimeout(userSearchTimer);
                if (userSearchXhr && userSearchXhr.readyState !== 4) { userSearchXhr.abort(); }
                $('#hlft-form-submit').text('Save Changes').prop('disabled', false);
                $('#hlft-detail-modal').hide();
                $('#hlft-form-modal').show();
                $('#hlft-form-title-input').focus();
            }

            function closeModal($modal) {
                $modal.hide();
                if ($modal.attr('id') === 'hlft-form-modal' && currentUuid) {
                    // If closing edit modal, reopen detail
                    // Only if we were editing (uuid was set)
                    if ($('#hlft-form-uuid').val()) {
                        openDetail(currentUuid);
                    }
                }
            }

            // ── Event Handlers ──

            // Filter changes
            $('#hlft-filter-type, #hlft-filter-status, #hlft-filter-priority').on('change', loadTickets);

            // Search (debounced 300ms, min 2 chars)
            var searchTimer;
            $('#hlft-search').on('input', function() {
                clearTimeout(searchTimer);
                var val = $(this).val();
                searchTimer = setTimeout(function() {
                    if (val.length === 0 || val.length >= 2) {
                        loadTickets();
                    }
                }, 300);
            });

            // Show all (clear default "hide closed" filter)
            $(document).on('click', '#hlft-show-all', function(e) {
                e.preventDefault();
                $('#hlft-filter-status').val('all');
                loadTickets();
            });

            // Clear filters
            $(document).on('click', '#hlft-clear-filters', function(e) {
                e.preventDefault();
                $('#hlft-filter-type').val('');
                $('#hlft-filter-status').val('');
                $('#hlft-filter-priority').val('');
                $('#hlft-search').val('');
                loadTickets();
            });

            // Context mode toggle
            $(document).on('change', '#hlft-form-context-mode', function() {
                if ($(this).val() === 'view_as') {
                    $('#hlft-context-user-wrap').show();
                    $('#hlft-user-search-input').show().focus();
                } else {
                    $('#hlft-context-user-wrap').hide();
                    $('#hlft-form-context-user-id').val('');
                    $('#hlft-context-user-chip').hide().empty();
                    $('#hlft-user-search-input').val('');
                    $('#hlft-user-search-results').hide().empty();
                }
            });

            // Prevent Enter in user search from submitting the ticket form
            $(document).on('keydown', '#hlft-user-search-input', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            // User search autocomplete
            $(document).on('input', '#hlft-user-search-input', function() {
                var val = $.trim($(this).val());
                var $results = $('#hlft-user-search-results');

                clearTimeout(userSearchTimer);

                if (val.length < 3) {
                    $results.hide().empty();
                    return;
                }

                userSearchTimer = setTimeout(function() {
                    if (userSearchXhr && userSearchXhr.readyState !== 4) {
                        userSearchXhr.abort();
                    }

                    $results.show().html('<div class="hlft-user-search-loading">Searching...</div>');

                    userSearchXhr = $.post(hlCoreAjax.ajaxurl, {
                        action: 'hl_ticket_user_search',
                        nonce: nonce,
                        search: val
                    }, function(resp) {
                        $results.empty();
                        userSearchResults = [];
                        if (!resp.success || !resp.data.length) {
                            $results.html('<div class="hlft-user-search-empty">No users found</div>');
                            return;
                        }
                        userSearchResults = resp.data;
                        $.each(resp.data, function(i, u) {
                            $results.append(
                                '<div class="hlft-user-search-item" data-index="' + i + '">' +
                                '<img class="hlft-avatar" src="' + esc(u.avatar_url) + '" alt=""> ' + esc(u.display_name) +
                                '</div>'
                            );
                        });
                    });
                }, 300);
            });

            // Select user from autocomplete
            $(document).on('click', '.hlft-user-search-item', function() {
                var idx = $(this).data('index');
                var u = userSearchResults[idx];
                if (!u) return;

                $('#hlft-form-context-user-id').val(u.user_id);
                $('#hlft-user-search-input').val('').hide();
                $('#hlft-user-search-results').hide().empty();
                $('#hlft-context-user-chip').show().html(
                    '<img class="hlft-avatar" src="' + esc(u.avatar_url) + '" alt=""> ' +
                    esc(u.display_name) +
                    ' <button type="button" class="hlft-chip-remove" title="Remove">&times;</button>'
                );
            });

            // Remove selected context user
            $(document).on('click', '.hlft-chip-remove', function() {
                $('#hlft-form-context-user-id').val('');
                $('#hlft-context-user-chip').hide().empty();
                $('#hlft-user-search-input').show().val('').focus();
            });

            // Row click → open detail
            $(document).on('click', '#hlft-table-body tr', function() {
                var uuid = $(this).data('uuid');
                if (uuid) openDetail(uuid);
            });

            // File attach buttons
            $('#hlft-form-attach-btn').on('click', function() { $('#hlft-form-file').click(); });
            $('#hlft-form-file').on('change', function() {
                pendingFormFiles = pendingFormFiles.concat(Array.from(this.files || []));
                showFilePreview($('#hlft-form-preview'), pendingFormFiles);
            });
            $('#hlft-comment-attach-btn').on('click', function() { $('#hlft-comment-file').click(); });
            $('#hlft-comment-file').on('change', function() {
                pendingCommentFiles = pendingCommentFiles.concat(Array.from(this.files || []));
                showFilePreview($('#hlft-comment-preview'), pendingCommentFiles);
            });

            // ── Clipboard Paste for Images ──

            var allowedPasteTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            var pasteExtMap = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp' };

            function handleImagePaste(e, pendingFilesRef, $previewContainer) {
                var items = (e.originalEvent || e).clipboardData && (e.originalEvent || e).clipboardData.items;
                if (!items) return;

                var added = false;
                for (var i = 0; i < items.length; i++) {
                    if (allowedPasteTypes.indexOf(items[i].type) === -1) continue;

                    var file = items[i].getAsFile();
                    if (!file) continue;

                    // Client-side size check (server enforces the real 5MB limit).
                    if (file.size > 0 && file.size > 5 * 1024 * 1024) {
                        showToast('Pasted image exceeds 5MB limit', true);
                        continue;
                    }

                    var ext = pasteExtMap[items[i].type] || '.png';
                    var rand = Math.random().toString(16).slice(2, 6);
                    var filename = 'pasted-image-' + Date.now() + '-' + rand + ext;
                    var namedFile = new File([file], filename, { type: items[i].type });

                    pendingFilesRef.push(namedFile);
                    added = true;
                }

                if (added) {
                    showFilePreview($previewContainer, pendingFilesRef);
                    showToast('Image pasted');
                }
            }

            // Bind paste to form textarea + upload area (NOT the whole modal).
            $('#hlft-form-description, #hlft-form-upload-area').on('paste', function(e) {
                handleImagePaste(e, pendingFormFiles, $('#hlft-form-preview'));
            });
            // Bind paste to comment textarea (delegated since it's in a modal).
            $(document).on('paste', '#hlft-comment-text', function(e) {
                handleImagePaste(e, pendingCommentFiles, $('#hlft-comment-preview'));
            });

            // New ticket button
            $('#hlft-new-ticket-btn').on('click', openCreateModal);

            // Edit button (inside detail modal)
            $(document).on('click', '#hlft-edit-btn', function() {
                // Fetch fresh ticket data for edit form
                ajax('hl_ticket_get', { ticket_uuid: currentUuid }, function(t) {
                    openEditModal(t);
                });
            });

            // Form submit (create or update)
            $('#hlft-ticket-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#hlft-form-submit');
                var uuid = $('#hlft-form-uuid').val();
                var isEdit = !!uuid;

                $btn.prop('disabled', true).text('Submitting...');

                var data = {
                    title: $('#hlft-form-title-input').val(),
                    category: $('#hlft-form-category').val(),
                    type: $('#hlft-form-type').val(),
                    priority: $('#hlft-form-priority').val(),
                    description: $('#hlft-form-description').val(),
                    context_mode: $('#hlft-form-context-mode').val(),
                    context_user_id: $('#hlft-form-context-user-id').val() || ''
                };

                // Submission guard: view_as mode requires a selected user.
                if (data.context_mode === 'view_as' && !data.context_user_id) {
                    showToast('Please select the user you were viewing as.', true);
                    $btn.prop('disabled', false).text(isEdit ? 'Save Changes' : 'Submit');
                    return;
                }

                // Error recovery: re-enable submit button on AJAX failure.
                var resetBtn = function() {
                    $btn.prop('disabled', false).text(isEdit ? 'Save Changes' : 'Submit');
                };

                if (isEdit) {
                    data.ticket_uuid = uuid;
                    ajax('hl_ticket_update', data, function(t) {
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, function() {
                                pendingFormFiles = [];
                                $btn.prop('disabled', false).text('Save Changes');
                                $('#hlft-form-modal').hide();
                                showToast('Ticket updated');
                                openDetail(t.ticket_uuid);
                                loadTickets();
                            });
                        } else {
                            $btn.prop('disabled', false).text('Save Changes');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket updated');
                            openDetail(t.ticket_uuid);
                            loadTickets();
                        }
                    }, resetBtn);
                } else {
                    ajax('hl_ticket_create', data, function(t) {
                        if (pendingFormFiles.length) {
                            uploadFiles(pendingFormFiles, t.ticket_uuid, null, function() {
                                pendingFormFiles = [];
                                $btn.prop('disabled', false).text('Submit');
                                $('#hlft-form-modal').hide();
                                showToast('Ticket #' + t.ticket_id + ' created');
                                currentUuid = null;
                                loadTickets();
                            });
                        } else {
                            $btn.prop('disabled', false).text('Submit');
                            $('#hlft-form-modal').hide();
                            showToast('Ticket #' + t.ticket_id + ' created');
                            currentUuid = null;
                            loadTickets();
                        }
                    }, resetBtn);
                }
            });

            // Post comment
            $('#hlft-comment-btn').on('click', function() {
                var $btn = $(this);
                var $textarea = $('#hlft-comment-text');
                var text = $.trim($textarea.val());
                if (!text && !pendingCommentFiles.length) return;

                $btn.prop('disabled', true).text('Posting...');

                ajax('hl_ticket_comment', {
                    ticket_uuid: currentUuid,
                    comment_text: text || '(image attached)'
                }, function(comment) {
                    function finishComment(attachments) {
                        $btn.prop('disabled', false).text('Post');
                        $textarea.val('');
                        $('#hlft-comment-file').val('');
                        $('#hlft-comment-preview').empty();
                        pendingCommentFiles = [];

                        // Remove "no comments" message if present
                        $('#hlft-comments-list .hlft-comments-empty').remove();

                        // Append new comment
                        var count = parseInt($('#hlft-comment-count').text(), 10) + 1;
                        $('#hlft-comment-count').text(count);

                        var attachHtml = buildAttachmentsHtml(attachments);
                        var html = '<div class="hlft-comment">' +
                            '<img class="hlft-avatar" src="' + esc(comment.user_avatar) + '" alt="">' +
                            '<div class="hlft-comment__body">' +
                            '<div class="hlft-comment__header"><span class="hlft-comment__name">' + esc(comment.user_name) + '</span><span class="hlft-comment__time">' + esc(comment.time_ago) + '</span></div>' +
                            '<div class="hlft-comment__text">' + esc(comment.comment_text) + '</div>' +
                            attachHtml +
                            '</div></div>';
                        $('#hlft-comments-list').append(html);
                    }

                    if (pendingCommentFiles.length) {
                        uploadFiles(pendingCommentFiles, currentUuid, comment.comment_id, function(uploaded) {
                            finishComment(uploaded);
                        });
                    } else {
                        finishComment([]);
                    }
                }, function() {
                    $btn.prop('disabled', false).text('Post');
                });
            });

            // Status change (admin only)
            $('#hlft-status-btn').on('click', function() {
                var $btn = $(this);
                var $sel = $('#hlft-status-select');
                var newStatus = $sel.val();

                $btn.prop('disabled', true);
                $sel.prop('disabled', true);

                ajax('hl_ticket_status', {
                    ticket_uuid: currentUuid,
                    status: newStatus
                }, function(t) {
                    $btn.prop('disabled', false);
                    $sel.prop('disabled', false);
                    showToast('Status updated to ' + statusLabels[newStatus]);

                    // Update pill in detail modal
                    $('#hlft-detail-meta .hlft-status-pill').attr('class', 'hlft-status-pill hlft-status-pill--' + t.status).text(statusLabels[t.status]);

                    // Refresh table
                    loadTickets();
                }, function() {
                    $btn.prop('disabled', false);
                    $sel.prop('disabled', false);
                });
            });

            // Close modals
            $(document).on('click', '[data-close-modal]', function() {
                closeModal($(this).closest('.hlft-modal'));
            });
            $(document).on('click', '.hlft-modal', function(e) {
                if ($(e.target).hasClass('hlft-modal')) {
                    closeModal($(this));
                }
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    var $visible = $('.hlft-modal:visible').last();
                    if ($visible.length) {
                        closeModal($visible);
                    }
                }
            });

            // ── Initial Load ──
            loadTickets();

        })();

    });

})(jQuery);

/**
 * Program Page v2 — toggle expandable panels.
 */
function hlTogglePanel(panelId, btn) {
    var panel = document.getElementById(panelId);
    if (!panel) return;

    var isOpen = panel.classList.contains('show');

    // Close all panels and deactivate all toggles.
    document.querySelectorAll('.hl-pp-panel').forEach(function(p) {
        p.classList.remove('show');
    });
    document.querySelectorAll('.hl-pp-toggle-btn').forEach(function(b) {
        b.classList.remove('active');
    });

    // If it wasn't open, open it.
    if (!isOpen) {
        panel.classList.add('show');
        btn.classList.add('active');
    }
}

/**
 * Sidebar collapse/expand toggle.
 * Works with the inline <script> in the sidebar template that sets body class
 * immediately on load to prevent FOUC.
 */
(function() {
    var btn = document.getElementById('hl-sidebar-collapse-btn');
    var sidebar = document.getElementById('hl-sidebar');
    if (!btn || !sidebar) return;

    btn.addEventListener('click', function() {
        var isCollapsed = sidebar.classList.toggle('hl-sidebar--collapsed');
        document.body.classList.toggle('hl-sidebar-is-collapsed', isCollapsed);
        localStorage.setItem('hl-sidebar-collapsed', isCollapsed ? '1' : '0');

        // Update button icon
        var icon = btn.querySelector('.dashicons');
        if (icon) {
            icon.className = isCollapsed
                ? 'dashicons dashicons-arrow-right-alt2'
                : 'dashicons dashicons-arrow-left-alt2';
        }
    });

    // Apply saved state on load (supplement the inline script that sets body class)
    if (localStorage.getItem('hl-sidebar-collapsed') === '1') {
        sidebar.classList.add('hl-sidebar--collapsed');
        var icon = btn.querySelector('.dashicons');
        if (icon) icon.className = 'dashicons dashicons-arrow-right-alt2';
    }
})();

/**
 * Course outline collapse/expand toggle (lesson pages only).
 * Persists state in localStorage so the outline stays collapsed/expanded
 * across page navigations within a course.
 */
(function() {
    var btn = document.getElementById('hl-course-outline-toggle');
    var outline = document.getElementById('hl-course-outline');
    if (!btn || !outline) return;

    btn.addEventListener('click', function() {
        var isCollapsed = outline.classList.toggle('hl-course-outline--collapsed');
        document.body.classList.toggle('hl-course-outline-is-collapsed', isCollapsed);
        localStorage.setItem('hl-course-outline-collapsed', isCollapsed ? '1' : '0');

        // Update button icon
        var icon = btn.querySelector('.dashicons');
        if (icon) {
            icon.className = isCollapsed
                ? 'dashicons dashicons-menu'
                : 'dashicons dashicons-no-alt';
        }
    });

    // Apply saved state on load
    if (localStorage.getItem('hl-course-outline-collapsed') === '1') {
        outline.classList.add('hl-course-outline--collapsed');
        document.body.classList.add('hl-course-outline-is-collapsed');
        var icon = btn.querySelector('.dashicons');
        if (icon) icon.className = 'dashicons dashicons-menu';
    }
})();
