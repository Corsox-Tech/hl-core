/**
 * HL Core Import Wizard
 *
 * Supports multiple import types: participants, children, classrooms, teaching_assignments.
 * Each type renders its own set of columns in the preview table.
 */
(function($) {
    'use strict';

    var HLImportWizard = {
        currentStep: 1,
        runId: 0,
        importType: 'participants',
        previewRows: [],

        init: function() {
            this.cacheDOM();
            this.bindEvents();
            this.updateColumnHints();
        },

        cacheDOM: function() {
            this.$wrap       = $('.hl-import-wizard-wrap');
            this.$steps      = this.$wrap.find('.hl-import-step');
            this.$panels     = this.$wrap.find('.hl-import-panel');
            this.$spinner    = this.$wrap.find('.hl-import-spinner');
            this.$spinnerMsg = this.$wrap.find('.hl-import-spinner-msg');
            this.$notices    = this.$wrap.find('.hl-import-notices');

            // Step 1
            this.$cohortSelect = $('#hl-import-cohort');
            this.$typeSelect   = $('#hl-import-type');
            this.$fileInput    = $('#hl-import-file');
            this.$uploadBtn    = $('#hl-import-upload-btn');

            // Step 2
            this.$summary       = $('#hl-import-summary');
            this.$selectAll     = $('#hl-import-select-all');
            this.$selectionCount = $('#hl-import-selection-count');
            this.$previewTable  = $('#hl-import-preview-table');
            this.$commitBtn     = $('#hl-import-commit-btn');
            this.$cancelBtn     = $('#hl-import-cancel-btn');

            // Step 3
            this.$results      = $('#hl-import-results');
            this.$errorList    = $('#hl-import-error-list');
            this.$downloadBtn  = $('#hl-import-download-errors-btn');
            this.$newImportBtn = $('#hl-import-new-btn');
        },

        bindEvents: function() {
            this.$uploadBtn.on('click', $.proxy(this.handleUpload, this));
            this.$commitBtn.on('click', $.proxy(this.handleCommit, this));
            this.$cancelBtn.on('click', $.proxy(this.handleCancel, this));
            this.$downloadBtn.on('click', $.proxy(this.handleDownloadErrors, this));
            this.$newImportBtn.on('click', $.proxy(this.handleNewImport, this));
            this.$selectAll.on('change', $.proxy(this.handleSelectAll, this));
            this.$wrap.on('click', '.hl-import-bulk-actions button', $.proxy(this.handleBulkAction, this));
            this.$previewTable.on('change', '.hl-row-checkbox', $.proxy(this.handleRowToggle, this));
            this.$typeSelect.on('change', $.proxy(this.updateColumnHints, this));
        },

        /**
         * Show/hide column hint descriptions based on selected import type
         */
        updateColumnHints: function() {
            var type = this.$typeSelect.val() || 'participants';
            $('#hl-import-column-hints .description').hide();
            $('#hl-import-column-hints .description[data-type="' + type + '"]').show();
        },

        goToStep: function(step) {
            this.currentStep = step;

            this.$steps.removeClass('active completed');
            this.$steps.each(function() {
                var s = parseInt($(this).data('step'), 10);
                if (s < step) {
                    $(this).addClass('completed');
                } else if (s === step) {
                    $(this).addClass('active');
                }
            });

            this.$panels.hide();
            $('#hl-import-step-' + step).show();
        },

        // == Step 1: Upload ==

        handleUpload: function(e) {
            e.preventDefault();

            var cohortId = this.$cohortSelect.val();
            if (!cohortId) {
                this.showNotice('error', hl_import_i18n.select_cohort);
                return;
            }

            var files = this.$fileInput[0].files;
            if (!files.length) {
                this.showNotice('error', hl_import_i18n.select_file);
                return;
            }

            this.importType = this.$typeSelect.val() || 'participants';

            var formData = new FormData();
            formData.append('action', 'hl_import_upload');
            formData.append('nonce', hl_import_i18n.nonce_upload);
            formData.append('cohort_id', cohortId);
            formData.append('import_type', this.importType);
            formData.append('file', files[0]);

            var self = this;
            this.showSpinner(hl_import_i18n.uploading);

            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) {
                        self.runId = resp.data.run_id;
                        self.importType = resp.data.import_type || self.importType;
                        self.previewRows = resp.data.rows;
                        self.renderPreview(resp.data);
                        self.goToStep(2);
                    } else {
                        self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                    }
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        // == Step 2: Preview ==

        /**
         * Get table column definitions based on import type
         */
        getColumns: function() {
            switch (this.importType) {
                case 'children':
                    return [
                        { key: 'name',       label: hl_import_i18n.col_name || 'Name' },
                        { key: 'dob',        label: hl_import_i18n.col_dob || 'DOB' },
                        { key: 'child_id',   label: hl_import_i18n.col_child_id || 'Child ID' },
                        { key: 'school',     label: hl_import_i18n.col_school || 'School' },
                        { key: 'classroom',  label: hl_import_i18n.col_classroom || 'Classroom' }
                    ];
                case 'classrooms':
                    return [
                        { key: 'classroom',  label: hl_import_i18n.col_classroom || 'Classroom' },
                        { key: 'school',     label: hl_import_i18n.col_school || 'School' },
                        { key: 'age_band',   label: hl_import_i18n.col_age_band || 'Age Band' }
                    ];
                case 'teaching_assignments':
                    return [
                        { key: 'email',      label: hl_import_i18n.col_email || 'Email' },
                        { key: 'classroom',  label: hl_import_i18n.col_classroom || 'Classroom' },
                        { key: 'school',     label: hl_import_i18n.col_school || 'School' },
                        { key: 'lead',       label: hl_import_i18n.col_lead || 'Lead' }
                    ];
                case 'participants':
                default:
                    return [
                        { key: 'email',    label: hl_import_i18n.col_email || 'Email' },
                        { key: 'name',     label: hl_import_i18n.col_name || 'Name' },
                        { key: 'roles',    label: hl_import_i18n.col_roles || 'Roles' },
                        { key: 'school',   label: hl_import_i18n.col_school || 'School' }
                    ];
            }
        },

        /**
         * Get cell value for a row based on column key
         */
        getCellValue: function(row, colKey) {
            switch (colKey) {
                case 'email':
                    return row.parsed_email || '';
                case 'name':
                    return $.trim((row.parsed_first_name || '') + ' ' + (row.parsed_last_name || ''));
                case 'roles':
                    return (row.parsed_roles || []).join(', ');
                case 'school':
                    return row.raw_school || '';
                case 'dob':
                    return row.parsed_dob || '';
                case 'child_id':
                    return row.parsed_child_identifier || '';
                case 'classroom':
                    return row.parsed_classroom_name || '';
                case 'age_band':
                    return row.parsed_age_band || '';
                case 'lead':
                    return row.parsed_is_lead ? 'Yes' : '';
                default:
                    return '';
            }
        },

        renderPreview: function(data) {
            // Summary cards
            var counts = data.counts;
            var html = '';
            html += this.summaryCard('create', 'CREATE', counts.CREATE);
            html += this.summaryCard('update', 'UPDATE', counts.UPDATE);
            html += this.summaryCard('skip', 'SKIP', counts.SKIP);
            html += this.summaryCard('needs-review', 'REVIEW', counts.NEEDS_REVIEW);
            html += this.summaryCard('error', 'ERROR', counts.ERROR);
            this.$summary.html(html);

            // Unmapped columns warning
            if (data.unmapped && data.unmapped.length > 0) {
                this.$summary.after(
                    '<div class="hl-import-unmapped">' +
                    hl_import_i18n.unmapped_columns + ': <strong>' +
                    $('<span>').text(data.unmapped.join(', ')).html() +
                    '</strong></div>'
                );
            }

            // Build table header dynamically based on import type
            var columns = this.getColumns();
            var $thead = this.$previewTable.find('thead tr');
            $thead.empty();
            $thead.append('<th class="col-checkbox"><input type="checkbox" id="hl-import-select-all-th" /></th>');
            $thead.append($('<th class="col-row-num">').text('#'));
            $thead.append($('<th>').text(hl_import_i18n.col_status));

            for (var c = 0; c < columns.length; c++) {
                $thead.append($('<th>').text(columns[c].label));
            }
            $thead.append($('<th>').text(hl_import_i18n.col_details));

            // Wire up header checkbox
            var self = this;
            $thead.find('#hl-import-select-all-th').on('change', function() {
                self.$selectAll.prop('checked', $(this).prop('checked')).trigger('change');
            });

            // Build table body
            var $tbody = this.$previewTable.find('tbody');
            $tbody.empty();

            for (var i = 0; i < this.previewRows.length; i++) {
                var row = this.previewRows[i];
                var selectable = (row.status === 'CREATE' || row.status === 'UPDATE' || row.status === 'NEEDS_REVIEW');
                var checked = row.selected && selectable;

                var $tr = $('<tr>').data('row-index', row.row_index);
                if (!checked) {
                    $tr.addClass('row-deselected');
                }

                // Checkbox
                var $cb = $('<input type="checkbox" class="hl-row-checkbox" />')
                    .val(row.row_index)
                    .prop('checked', checked)
                    .prop('disabled', !selectable);
                $tr.append($('<td class="col-checkbox">').append($cb));

                // Row number
                $tr.append($('<td class="col-row-num">').text(row.row_index + 1));

                // Status badge
                var statusClass = row.status.toLowerCase().replace('_', '-');
                $tr.append($('<td>').append($('<span class="hl-import-status ' + statusClass + '">').text(row.status)));

                // Dynamic data columns
                for (var j = 0; j < columns.length; j++) {
                    $tr.append($('<td>').text(this.getCellValue(row, columns[j].key)));
                }

                // Details (messages + actions)
                var $details = $('<td>');
                if (row.proposed_actions.length > 0) {
                    var $actions = $('<ul class="hl-import-cell-messages">');
                    for (var a = 0; a < row.proposed_actions.length; a++) {
                        $actions.append($('<li>').text(row.proposed_actions[a]));
                    }
                    $details.append($actions);
                }
                if (row.validation_messages.length > 0) {
                    var $msgs = $('<ul class="hl-import-cell-messages" style="color:#d63638;">');
                    for (var m = 0; m < row.validation_messages.length; m++) {
                        $msgs.append($('<li>').text(row.validation_messages[m]));
                    }
                    $details.append($msgs);
                }
                $tr.append($details);

                $tbody.append($tr);
            }

            this.updateSelectionCounts();
        },

        summaryCard: function(cls, label, count) {
            return '<div class="hl-import-summary-card ' + cls + '">' +
                '<div class="count">' + count + '</div>' +
                '<div class="label">' + label + '</div>' +
                '</div>';
        },

        handleRowToggle: function(e) {
            var $cb = $(e.target);
            var $tr = $cb.closest('tr');
            var idx = parseInt($cb.val(), 10);

            if ($cb.prop('checked')) {
                $tr.removeClass('row-deselected');
            } else {
                $tr.addClass('row-deselected');
            }

            // Update data
            for (var i = 0; i < this.previewRows.length; i++) {
                if (this.previewRows[i].row_index === idx) {
                    this.previewRows[i].selected = $cb.prop('checked');
                    break;
                }
            }

            this.updateSelectionCounts();
        },

        handleSelectAll: function(e) {
            var checked = $(e.target).prop('checked');
            this.$previewTable.find('.hl-row-checkbox:not(:disabled)').each(function() {
                $(this).prop('checked', checked).closest('tr').toggleClass('row-deselected', !checked);
            });

            for (var i = 0; i < this.previewRows.length; i++) {
                var r = this.previewRows[i];
                if (r.status === 'CREATE' || r.status === 'UPDATE' || r.status === 'NEEDS_REVIEW') {
                    r.selected = checked;
                }
            }

            this.updateSelectionCounts();
        },

        handleBulkAction: function(e) {
            var action = $(e.target).data('bulk');
            var self = this;

            this.$previewTable.find('.hl-row-checkbox:not(:disabled)').each(function() {
                var $cb = $(this);
                var idx = parseInt($cb.val(), 10);
                var row = null;

                for (var i = 0; i < self.previewRows.length; i++) {
                    if (self.previewRows[i].row_index === idx) {
                        row = self.previewRows[i];
                        break;
                    }
                }

                if (!row) return;

                var shouldCheck = false;
                if (action === 'create') {
                    shouldCheck = (row.status === 'CREATE');
                } else if (action === 'update') {
                    shouldCheck = (row.status === 'UPDATE');
                }
                // action === 'none': shouldCheck stays false

                $cb.prop('checked', shouldCheck);
                $cb.closest('tr').toggleClass('row-deselected', !shouldCheck);
                row.selected = shouldCheck;
            });

            this.updateSelectionCounts();
        },

        updateSelectionCounts: function() {
            var count = this.$previewTable.find('.hl-row-checkbox:checked').length;
            var total = this.$previewTable.find('.hl-row-checkbox:not(:disabled)').length;
            this.$selectionCount.text(count + ' / ' + total + ' ' + hl_import_i18n.selected);

            // Sync header checkbox
            this.$selectAll.prop('checked', count === total && total > 0);
            this.$wrap.find('#hl-import-select-all-th').prop('checked', count === total && total > 0);
        },

        // == Commit ==

        handleCommit: function(e) {
            e.preventDefault();

            var selected = [];
            this.$previewTable.find('.hl-row-checkbox:checked').each(function() {
                selected.push(parseInt($(this).val(), 10));
            });

            if (selected.length === 0) {
                this.showNotice('error', hl_import_i18n.no_rows_selected);
                return;
            }

            if (!confirm(hl_import_i18n.confirm_commit.replace('%d', selected.length))) {
                return;
            }

            var self = this;
            this.showSpinner(hl_import_i18n.committing);

            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: {
                    action: 'hl_import_commit',
                    nonce: hl_import_i18n.nonce_commit,
                    run_id: this.runId,
                    selected_rows: selected
                },
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) {
                        self.renderResults(resp.data);
                        self.goToStep(3);
                    } else {
                        self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                    }
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        handleCancel: function(e) {
            e.preventDefault();
            if (confirm(hl_import_i18n.confirm_cancel)) {
                this.goToStep(1);
                this.resetState();
            }
        },

        // == Step 3: Results ==

        renderResults: function(data) {
            var html = '';
            html += this.resultCard('created', hl_import_i18n.created, data.created_count);
            html += this.resultCard('updated', hl_import_i18n.updated, data.updated_count);
            html += this.resultCard('skipped', hl_import_i18n.skipped, data.skipped_count);
            html += this.resultCard('errors', hl_import_i18n.errors_label, data.error_count);
            this.$results.html(html);

            // Error details
            if (data.errors && data.errors.length > 0) {
                var $table = $('<table class="widefat striped">');
                var $head = $('<thead><tr></tr></thead>');
                $head.find('tr')
                    .append($('<th>').text(hl_import_i18n.col_row))
                    .append($('<th>').text(this.importType === 'participants' || this.importType === 'teaching_assignments' ? (hl_import_i18n.col_email || 'Email') : (hl_import_i18n.col_name || 'Name')))
                    .append($('<th>').text(hl_import_i18n.col_error));
                $table.append($head);

                var $body = $('<tbody>');
                for (var i = 0; i < data.errors.length; i++) {
                    var err = data.errors[i];
                    var $row = $('<tr>');
                    $row.append($('<td>').text(err.row_index !== undefined ? err.row_index + 1 : '-'));
                    $row.append($('<td>').text(err.email || err.name || '-'));
                    $row.append($('<td>').text(err.message));
                    $body.append($row);
                }
                $table.append($body);
                this.$errorList.html('<h3>' + hl_import_i18n.commit_errors + '</h3>').append($table);
                this.$downloadBtn.show();
            } else {
                this.$errorList.html('<p style="color:#00a32a;font-weight:600;">' + hl_import_i18n.all_success + '</p>');
                this.$downloadBtn.hide();
            }
        },

        resultCard: function(cls, label, count) {
            return '<div class="hl-import-result-card ' + cls + '">' +
                '<div class="count">' + count + '</div>' +
                '<div class="label">' + label + '</div>' +
                '</div>';
        },

        handleDownloadErrors: function(e) {
            e.preventDefault();
            var self = this;
            this.showSpinner(hl_import_i18n.generating_report);

            $.ajax({
                url: hl_import_i18n.ajax_url,
                type: 'POST',
                data: {
                    action: 'hl_import_error_report',
                    nonce: hl_import_i18n.nonce_error_report,
                    run_id: this.runId
                },
                success: function(resp) {
                    self.hideSpinner();
                    if (resp.success) {
                        window.open(resp.data.url, '_blank');
                    } else {
                        self.showNotice('error', resp.data.message || hl_import_i18n.unknown_error);
                    }
                },
                error: function() {
                    self.hideSpinner();
                    self.showNotice('error', hl_import_i18n.unknown_error);
                }
            });
        },

        handleNewImport: function(e) {
            e.preventDefault();
            this.goToStep(1);
            this.resetState();
        },

        // == Helpers ==

        resetState: function() {
            this.runId = 0;
            this.importType = this.$typeSelect.val() || 'participants';
            this.previewRows = [];
            this.$fileInput.val('');
            this.$summary.empty();
            this.$previewTable.find('thead tr').empty();
            this.$previewTable.find('tbody').empty();
            this.$results.empty();
            this.$errorList.empty();
            this.$notices.empty();
            this.$wrap.find('.hl-import-unmapped').remove();
        },

        showSpinner: function(msg) {
            this.$spinnerMsg.text(msg || hl_import_i18n.processing);
            this.$spinner.show();
        },

        hideSpinner: function() {
            this.$spinner.hide();
        },

        showNotice: function(type, message) {
            var cls = (type === 'error') ? 'notice-error' : 'notice-success';
            var $notice = $('<div class="notice ' + cls + ' is-dismissible"><p></p></div>');
            $notice.find('p').text(message);
            this.$notices.html($notice);

            // Auto-dismiss after 8s
            setTimeout(function() { $notice.fadeOut(); }, 8000);
        }
    };

    $(document).ready(function() {
        if ($('.hl-import-wizard-wrap').length) {
            HLImportWizard.init();
        }
    });

})(jQuery);
