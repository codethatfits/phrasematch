/**
 * PhraseMatch Admin JS
 *
 * Handles AJAX scanning, results rendering, occurrence selection,
 * confirmation modal, and removal requests.
 */
(function ($) {
    'use strict';

    var data       = window.PhraseMatchData || {};
    var lastPhrase = '';

    // Pagination state.
    var allResults   = [];
    var totalResults = 0;
    var currentPage  = 1;
    var perPage      = Math.max(1, parseInt(data.per_page, 10) || 15);

    // Cache DOM elements.
    var $phrase        = $('#phrasematch-phrase');
    var $scanBtn       = $('#phrasematch-scan-btn');
    var $results       = $('#phrasematch-results');
    var $heading       = $('#phrasematch-results-heading');
    var $notices       = $('#phrasematch-results-notices');
    var $tbody         = $('#phrasematch-results-body');
    var $selectAll     = $('#phrasematch-select-all');
    var $removeBtn     = $('#phrasematch-remove-btn');
    var $rescanBtn     = $('#phrasematch-rescan-btn');
    var $removeSpinner = $('#phrasematch-remove-spinner');
    var $selCount      = $('#phrasematch-selection-count');
    var $bulkReplace   = $('#phrasematch-bulk-replace');
    var $pagination    = $('#phrasematch-pagination');
    var $modal         = $('#phrasematch-modal');
    var $modalSummary  = $('#phrasematch-modal-summary');
    var $modalConfirm  = $('#phrasematch-modal-confirm');
    var $modalCancel   = $('#phrasematch-modal-cancel');
    var $backdrop      = $('.pm-modal-backdrop');

    // -------------------------------------------------------------------------
    // Scan
    // -------------------------------------------------------------------------

    $scanBtn.on('click', runScan);

    // Ctrl+Enter (or Cmd+Enter) triggers scan; plain Enter adds a newline.
    $phrase.on('keydown', function (e) {
        if (e.which === 13 && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            runScan();
        }
    });

    // Auto-resize the textarea as the user types.
    $phrase.on('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });

    $rescanBtn.on('click', runScan);

    // Bulk replace: fill all checked rows' replace inputs.
    $bulkReplace.on('input', function () {
        var val = $(this).val();
        $('.phrasematch-row-cb:checked').each(function () {
            $(this).closest('tr').find('.pm-replace-input').val(val).trigger('input');
        });
    });

    function runScan() {
        var phrase = $.trim($phrase.val());
        if (!phrase) {
            $phrase.focus();
            return;
        }

        var postTypes = [];
        $('input[name="phrasematch_post_types[]"]:checked').each(function () {
            postTypes.push($(this).val());
        });
        if (!postTypes.length) {
            return;
        }

        var statuses = [];
        $('input[name="phrasematch_statuses[]"]:checked').each(function () {
            statuses.push($(this).val());
        });

        lastPhrase = phrase;

        $scanBtn.prop('disabled', true);
        $results.hide();
        $tbody.empty();
        $notices.empty();
        $pagination.hide().empty();

        $.post(data.ajax_url, {
            action:     'phrasematch_scan',
            nonce:      data.nonce,
            phrase:     phrase,
            post_types: postTypes,
            statuses:   statuses
        })
        .done(function (response) {
            if (response.success) {
                renderResults(response.data.results, response.data.total, response.data.phrase);
            } else {
                showNotice('error', response.data.message || 'An error occurred.');
                $results.show();
            }
        })
        .fail(function () {
            showNotice('error', 'Request failed. Please try again.');
            $results.show();
        })
        .always(function () {
            $scanBtn.prop('disabled', false);
        });
    }

    // -------------------------------------------------------------------------
    // Render results (with pagination)
    // -------------------------------------------------------------------------

    function renderResults(results, total, phrase) {
        $notices.empty();
        $selectAll.prop('checked', false);
        $removeBtn.prop('disabled', true);
        $rescanBtn.hide();
        $bulkReplace.val('');
        updateSelectionCount();

        allResults   = results || [];
        totalResults = total || 0;
        currentPage  = 1;

        if (!allResults.length) {
            $heading.text('No matches found for "' + phrase + '"');
            $pagination.hide().empty();
            $tbody.empty();
            $results.show();
            return;
        }

        $heading.text(totalResults + ' occurrence' + (totalResults !== 1 ? 's' : '') + ' found');
        renderCurrentPage();
        renderPagination();
        $results.show();
    }

    function renderCurrentPage() {
        $tbody.empty();
        $selectAll.prop('checked', false);
        $removeBtn.prop('disabled', true);
        updateSelectionCount();

        var start = (currentPage - 1) * perPage;
        var pageResults = allResults.slice(start, start + perPage);
        if (!pageResults.length) return;

        // Group current page items by post_id for visual grouping (post title shown once per group).
        var grouped = {};
        var order   = [];
        pageResults.forEach(function (r) {
            if (!grouped[r.post_id]) {
                grouped[r.post_id] = [];
                order.push(r.post_id);
            }
            grouped[r.post_id].push(r);
        });

        order.forEach(function (postId) {
            var items = grouped[postId];
            items.forEach(function (item, idx) {
                var $row = buildResultRow(item, items, idx);
                $tbody.append($row);
            });
        });
    }

    function buildResultRow(item, items, idx) {
        var $row = $('<tr></tr>');

        var $cbTd = $('<td class="pm-col-cb"></td>');
        $cbTd.append(
            $('<input type="checkbox" class="phrasematch-row-cb" />')
                .data('post-id', item.post_id)
                .data('char-offset', item.char_offset)
                .data('location', item.location)
        );
        $row.append($cbTd);

        var $titleTd = $('<td class="pm-col-post"></td>');
        if (idx === 0) {
            var $link = $('<a class="pm-post-link"></a>')
                .attr('href', item.edit_url)
                .attr('target', '_blank')
                .text(item.title || '(no title)');
            $titleTd.append($link);
            var metaHtml = '<span class="pm-badge pm-badge-type">' + escHtml(item.post_type) + '</span> ' +
                           '<span class="pm-badge pm-badge-status">' + escHtml(item.post_status) + '</span>';
            if (items.length > 1) {
                metaHtml += ' <span class="pm-badge pm-badge-count">' + items.length + ' matches</span>';
            }
            var $meta = $('<span class="pm-post-meta"></span>').html(metaHtml);
            if (item.revisions_url) {
                $meta.append(
                    ' &middot; ',
                    $('<a></a>').attr('href', item.revisions_url).attr('target', '_blank').text('Revisions')
                );
            }
            $titleTd.append($meta);
        }
        $row.append($titleTd);

        var $locTd = $('<td class="pm-col-location"></td>');
        $locTd.html(item.location === 'title'
            ? '<span class="pm-badge pm-badge-title">Title</span>'
            : '<span class="pm-badge pm-badge-content">Content</span>');
        $row.append($locTd);

        var $ctxTd = $('<td class="pm-col-context"></td>');
        $ctxTd.append('<code class="pm-snippet">' + item.snippet + '</code>');
        $row.append($ctxTd);

        var $replaceTd = $('<td class="pm-col-replace"></td>');
        var $replaceInput = $('<input type="text" class="pm-replace-input" />')
            .attr('placeholder', 'Leave empty to remove')
            .on('input', function () {
                var hasValue = !!$(this).val();
                var $modeSelect = $(this).closest('tr').find('.pm-mode-select');
                if (hasValue) {
                    $modeSelect.prop('disabled', true).addClass('pm-mode-disabled');
                } else {
                    var isTitle = $(this).closest('tr').find('.phrasematch-row-cb').data('location') === 'title';
                    $modeSelect.prop('disabled', isTitle).removeClass('pm-mode-disabled');
                }
            });
        $replaceTd.append($replaceInput);
        $row.append($replaceTd);

        var $modeTd = $('<td class="pm-col-mode"></td>');
        var $select = $('<select class="pm-mode-select phrasematch-mode-select"></select>');
        $select.append('<option value="text_only">Text only</option>');
        if (item.location === 'title') {
            $select.prop('disabled', true);
        } else {
            var htmlDisabled  = (item.wrapping !== 'html_element' && item.wrapping !== 'gutenberg_block');
            var blockDisabled = (item.wrapping !== 'gutenberg_block');
            $select.append($('<option value="html_element">HTML element</option>').prop('disabled', htmlDisabled));
            $select.append($('<option value="gutenberg_block">Gutenberg block</option>').prop('disabled', blockDisabled));
            if (item.wrapping === 'gutenberg_block') $select.val('gutenberg_block');
            else if (item.wrapping === 'html_element') $select.val('html_element');
        }
        $modeTd.append($select);
        $row.append($modeTd);
        return $row;
    }

    function renderPagination() {
        var totalPages = Math.ceil(totalResults / perPage);
        if (totalPages <= 1) {
            $pagination.hide().empty();
            return;
        }

        var start = (currentPage - 1) * perPage + 1;
        var end   = Math.min(currentPage * perPage, totalResults);

        var $nav = $('<nav class="pm-pagination-nav" aria-label="Results pages"></nav>');
        var $info = $('<span class="pm-pagination-info"></span>')
            .text('Showing ' + start + '\u2013' + end + ' of ' + totalResults);

        var $list = $('<ul class="pm-pagination-list"></ul>');

        // Previous
        var $prevLi = $('<li></li>');
        var $prevBtn = $('<button type="button" class="pm-page-btn pm-page-prev">Previous</button>');
        if (currentPage <= 1) $prevBtn.prop('disabled', true).addClass('disabled');
        else $prevBtn.on('click', function () { goToPage(currentPage - 1); });
        $prevLi.append($prevBtn);
        $list.append($prevLi);

        // Page numbers (show up to 7: first, ... mid ... last)
        var maxVisible = 5;
        var from = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        var to   = Math.min(totalPages, from + maxVisible - 1);
        if (to - from + 1 < maxVisible) from = Math.max(1, to - maxVisible + 1);
        if (from > 1) {
            $list.append($('<li></li>').append($('<button type="button" class="pm-page-btn pm-page-num">1</button>').on('click', function () { goToPage(1); })));
            if (from > 2) $list.append($('<li class="pm-pagination-ellipsis"><span>…</span></li>'));
        }
        for (var p = from; p <= to; p++) {
            (function (page) {
                var $btn = $('<button type="button" class="pm-page-btn pm-page-num">' + page + '</button>');
                if (page === currentPage) $btn.addClass('current');
                else $btn.on('click', function () { goToPage(page); });
                $list.append($('<li></li>').append($btn));
            })(p);
        }
        if (to < totalPages) {
            if (to < totalPages - 1) $list.append($('<li class="pm-pagination-ellipsis"><span>…</span></li>'));
            $list.append($('<li></li>').append($('<button type="button" class="pm-page-btn pm-page-num">' + totalPages + '</button>').on('click', function () { goToPage(totalPages); })));
        }

        // Next
        var $nextLi = $('<li></li>');
        var $nextBtn = $('<button type="button" class="pm-page-btn pm-page-next">Next</button>');
        if (currentPage >= totalPages) $nextBtn.prop('disabled', true).addClass('disabled');
        else $nextBtn.on('click', function () { goToPage(currentPage + 1); });
        $nextLi.append($nextBtn);
        $list.append($nextLi);

        $nav.append($info).append($list);
        $pagination.empty().append($nav).show();
    }

    function goToPage(page) {
        var totalPages = Math.ceil(totalResults / perPage);
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        renderCurrentPage();
        renderPagination();
        $results.show();
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    $selectAll.on('change', function () {
        var checked = $(this).is(':checked');
        $('.phrasematch-row-cb').prop('checked', checked);
        updateRemoveButton();
    });

    $(document).on('change', '.phrasematch-row-cb', function () {
        updateRemoveButton();
        if (!$(this).is(':checked')) {
            $selectAll.prop('checked', false);
        } else {
            var allChecked = ($('.phrasematch-row-cb').length === $('.phrasematch-row-cb:checked').length);
            $selectAll.prop('checked', allChecked);
        }
    });

    function updateRemoveButton() {
        var count = $('.phrasematch-row-cb:checked').length;
        $removeBtn.prop('disabled', count === 0);
        updateSelectionCount();
    }

    function updateSelectionCount() {
        var count = $('.phrasematch-row-cb:checked').length;
        if (count > 0) {
            $selCount.text(count + ' selected');
        } else {
            $selCount.text('');
        }
    }

    // -------------------------------------------------------------------------
    // Removal with confirmation modal
    // -------------------------------------------------------------------------

    $removeBtn.on('click', function () {
        var items = getSelectedItems();
        if (!items.length) return;
        showConfirmationModal(items);
    });

    $modalConfirm.on('click', function () {
        hideModal();
        executeRemoval();
    });

    $modalCancel.on('click', hideModal);
    $backdrop.on('click', hideModal);

    // Close on Escape key.
    $(document).on('keydown', function (e) {
        if (e.which === 27 && $modal.is(':visible')) {
            hideModal();
        }
    });

    function getSelectedItems() {
        var items = [];
        $('.phrasematch-row-cb:checked').each(function () {
            var $cb  = $(this);
            var $row = $cb.closest('tr');
            items.push({
                post_id:      $cb.data('post-id'),
                char_offset:  $cb.data('char-offset'),
                location:     $cb.data('location'),
                mode:         $row.find('.phrasematch-mode-select').val(),
                replace_with: $.trim($row.find('.pm-replace-input').val())
            });
        });
        return items;
    }

    function showConfirmationModal(items) {
        var grouped = {};
        items.forEach(function (it) {
            if (!grouped[it.post_id]) {
                grouped[it.post_id] = { title: '', items: [] };
            }
            grouped[it.post_id].items.push(it);
        });

        // Gather titles from the table.
        $('.phrasematch-row-cb:checked').each(function () {
            var pid   = $(this).data('post-id');
            var $row  = $(this).closest('tr');
            var title = $row.find('.pm-post-link').first().text();
            if (title && grouped[pid]) {
                grouped[pid].title = title;
            }
        });

        // Count replacements vs removals.
        var totalReplacements = 0;
        var totalRemovals     = 0;
        items.forEach(function (it) {
            if (it.replace_with) {
                totalReplacements++;
            } else {
                totalRemovals++;
            }
        });

        var summaryParts = [];
        if (totalRemovals > 0) {
            summaryParts.push(totalRemovals + ' removal' + (totalRemovals !== 1 ? 's' : ''));
        }
        if (totalReplacements > 0) {
            summaryParts.push(totalReplacements + ' replacement' + (totalReplacements !== 1 ? 's' : ''));
        }

        var html = '<p>You are about to modify <strong>' + items.length +
                   '</strong> occurrence' + (items.length !== 1 ? 's' : '') +
                   ' of &ldquo;' + escHtml(lastPhrase) + '&rdquo; (' +
                   summaryParts.join(', ') + '):</p><ul>';

        Object.keys(grouped).forEach(function (pid) {
            var g = grouped[pid];
            var postTitle = escHtml(g.title || 'Post #' + pid);

            // Break down per post.
            var replaceItems = [];
            var removeCount  = 0;
            g.items.forEach(function (it) {
                if (it.replace_with) {
                    replaceItems.push(it.replace_with);
                } else {
                    removeCount++;
                }
            });

            var details = [];
            if (removeCount > 0) {
                details.push(removeCount + ' removed');
            }

            // Group replacements by their replacement text.
            if (replaceItems.length > 0) {
                var replaceCounts = {};
                replaceItems.forEach(function (txt) {
                    replaceCounts[txt] = (replaceCounts[txt] || 0) + 1;
                });
                Object.keys(replaceCounts).forEach(function (txt) {
                    details.push(replaceCounts[txt] + ' replaced with &ldquo;' + escHtml(txt) + '&rdquo;');
                });
            }

            html += '<li><strong>' + postTitle + '</strong> &mdash; ' + details.join(', ') + '</li>';
        });

        html += '</ul><p>Changes can be reverted via the Revisions screen of each post.</p>';

        $modalSummary.html(html);
        $modal.show();
    }

    function hideModal() {
        $modal.hide();
    }

    function executeRemoval() {
        var items = getSelectedItems();
        if (!items.length) return;

        $removeBtn.prop('disabled', true);
        $removeSpinner.addClass('is-active');

        $.post(data.ajax_url, {
            action: 'phrasematch_remove',
            nonce:  data.nonce,
            phrase: lastPhrase,
            items:  JSON.stringify(items)
        })
        .done(function (response) {
            if (response.success) {
                renderRemovalResults(response.data.results);
            } else {
                showNotice('error', response.data.message || 'An error occurred during removal.');
            }
        })
        .fail(function () {
            showNotice('error', 'Request failed. Please try again.');
        })
        .always(function () {
            $removeBtn.prop('disabled', false);
            $removeSpinner.removeClass('is-active');
        });
    }

    function renderRemovalResults(results) {
        $notices.empty();

        results.forEach(function (r) {
            var type = r.success ? 'success' : 'error';
            var msg  = '<strong>' + escHtml(r.title) + '</strong>: ' + escHtml(r.message);

            if (r.success && r.revisions_url) {
                msg += ' &mdash; <a href="' + escAttr(r.revisions_url) + '" target="_blank">View Revisions</a>';
            }

            showNotice(type, msg);
        });

        $rescanBtn.show();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function showNotice(type, messageHtml) {
        var $notice = $(
            '<div class="notice notice-' + type + ' inline is-dismissible">' +
            '<p>' + messageHtml + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">Dismiss</span></button>' +
            '</div>'
        );

        $notice.find('.notice-dismiss').on('click', function () {
            $notice.fadeOut(200, function () { $(this).remove(); });
        });

        $notices.append($notice);
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})(jQuery);
