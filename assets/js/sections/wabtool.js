// WAB from Gridsquare tool: batch assign WAB squares from logged gridsquares
// (wabtool* prefix because section scripts share the global scope; escapeHtml
// comes from common.js, showWabMapModal from wab.js). Server-side DataTable
// (only one page is ever fetched); selection state in wabtoolSelected plus
// the wabtoolAllMatching flag ("apply everything the scan matches").
// wabtoolOnlyFull filters to "100% matches" (grids fully inside one square).
// Confirmed QSOs are shown but never selectable; the server skips them too.

var wabtoolTable = null; // DataTables API instance of the preview table
var wabtoolSelected = {}; // qso id -> 1, rows checked by the user
var wabtoolAllMatching = false; // apply every matching QSO, not just checked ones
var wabtoolOnlyFull = true; // only gridsquares fully inside a single WAB square; on by default
var wabtoolRecordsFiltered = 0; // rows matching the current table filter
var wabtoolSummaryPending = false; // request the one-time scan summary on the next ajax

function wabtoolCornerTooltip(cornerSquares) {
	var squares = (cornerSquares && cornerSquares.length) ? cornerSquares.join(', ') : '';
	return '<span class="text-warning" data-bs-toggle="tooltip" title="Gridsquare corners fall into: ' + escapeHtml(squares) + '"><i class="fas fa-exclamation-triangle"></i></span>';
}

function wabtoolRenderSummary(summary) {
	var html = '';
	if (summary) {
		html += '<p class="mb-2">'
			+ '<strong>' + summary.qsos_scanned + '</strong> QSOs with gridsquare &middot; '
			+ '<strong>' + summary.unique_grids + '</strong> unique grids &middot; '
			+ '<strong>' + summary.matched + '</strong> matched to a WAB square';
		if (summary.ambiguous) {
			html += ' &middot; <span class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' + summary.ambiguous + ' straddling square boundaries</span>';
		}
		if (summary.unmatched) {
			html += ' &middot; ' + summary.unmatched + ' outside WAB coverage';
		}
		html += '</p>';
	}
	html += '<button type="button" class="btn btn-sm btn-outline-success mb-2 me-2' + (wabtoolOnlyFull ? ' active' : '') + '" id="wabtoolOnlyFull" aria-pressed="' + wabtoolOnlyFull + '" data-bs-toggle="tooltip" title="Hide gridsquares that straddle a WAB square boundary (or fall outside WAB coverage)">Only 100% matches</button>';
	html += '<button type="button" class="btn btn-sm btn-outline-primary mb-2" id="wabtoolSelectAllMatching"></button>';
	$('.wabtool-summary').html(html);
	$('#wabtoolOnlyFull').tooltip();
}

function wabtoolUpdateSelectAllButton() {
	var btn = $('#wabtoolSelectAllMatching');
	if (!btn.length) {
		return;
	}
	if (wabtoolAllMatching) {
		btn.text('Clear selection (' + wabtoolRecordsFiltered + ' selected)');
	} else {
		var n = wabtoolRecordsFiltered;
		var what = wabtoolOnlyFull ? ' 100% matching QSOs' : ' matching QSOs';
		btn.text(n ? 'Select all ' + n + what : 'Select all matching QSOs');
	}
}

function wabtoolSyncHeaderCheckbox() {
	// only selectable (enabled) rows count; confirmed rows are greyed out
	var $rows = $('#wabtoolTable tbody .wabtool-row:enabled');
	$('#wabtoolSelectAll').prop('checked', $rows.length > 0 && $rows.length === $rows.filter(':checked').length);
}

// Render the preview table shell and let DataTables fetch page 1 itself
function wabtoolInitTable() {
	var html = '<div class="wabtool-summary"></div>'
		+ '<div class="table-responsive"><table class="table table-sm table-striped" id="wabtoolTable">'
		+ '<thead><tr>'
		+ '<th style="width: 2rem;"><input type="checkbox" id="wabtoolSelectAll"></th>'
		+ '<th>Date/Time</th><th>Callsign</th><th>Band</th><th>Grid</th><th>WAB Square</th><th>Station</th><th>Confirmed</th>'
		+ '</tr></thead><tbody></tbody></table></div>';

	$('.scanresult').html(html);

	wabtoolTable = $('#wabtoolTable').DataTable({
		serverSide: true,
		processing: true,
		ajax: {
			url: site_url + '/wabtool/scan',
			type: 'POST',
			data: function(d) {
				d.station_id = $('#de').val();
				if (wabtoolOnlyFull) {
					d.only_full = 1;
				}
				if (wabtoolSummaryPending) {
					// whole-log summary only on the first load after a scan
					d.wabtool_summary = 1;
					wabtoolSummaryPending = false;
				}
			}
		},
		pageLength: 25,
		lengthMenu: [10, 25, 50, 100],
		order: [[1, 'desc']],
		searchDelay: 400,
		language: {
			url: getDataTablesLanguageUrl(),
		},
		columns: [
		{
			data: null,
			orderable: false,
			searchable: false,
			render: function(data, type, row) {
				if (type !== 'display') {
					return '';
				}
				if (!row.square) {
					return ''; // unresolvable rows are never selectable
				}
				if (row.confirmed) {
					// already confirmed QSOs are never modified by this tool
					return '<input type="checkbox" class="wabtool-row" value="' + row.id + '" disabled title="Already confirmed QSO">';
				}
				return '<input type="checkbox" class="wabtool-row" value="' + row.id + '"'
					+ ((wabtoolAllMatching || wabtoolSelected[row.id]) ? ' checked' : '') + '>';
			}
		},
			{ data: 'datetime', render: wabtoolRenderCell },
			{
				data: 'callsign',
				className: 'callsign',
				render: function(data, type, row) {
					if (type !== 'display') {
						return data;
					}
					return '<a href="#" class="wabtool-qso" data-id="' + row.id + '">' + escapeHtml(data) + '</a>';
				}
			},
			{
				data: 'band',
				render: function(data, type, row) {
					if (type !== 'display') {
						return data;
					}
					// sat QSOs: show the satellite instead of the bare 'SAT' band
					if (row.sat) {
						return escapeHtml(row.sat);
					}
					return escapeHtml(data);
				}
			},
			{ data: 'grid', render: wabtoolRenderCell },
			{
				data: 'square',
				orderable: false,
				render: function(data, type, row) {
					if (type !== 'display') {
						return row.square || '';
					}
					var html = row.square
						? '<span class="badge bg-primary">' + escapeHtml(row.square) + '</span>'
						: '<span class="text-muted">&mdash;</span>';
					if (row.square && row.ambiguous) {
						html += ' ' + wabtoolCornerTooltip(row.corner_squares);
					}
					html += ' <a href="#" class="wabtool-map text-muted" data-square="' + escapeHtml(row.square || '') + '"'
					+ ' data-call="' + escapeHtml(row.callsign || '') + '"'
					+ ' data-lat="' + (row.lat === null ? '' : row.lat) + '" data-lng="' + (row.lng === null ? '' : row.lng) + '"'
					+ ' data-bs-toggle="tooltip" title="Show on map"><i class="fas fa-map-marked-alt"></i></a>';
					return html;
				}
			},
			{ data: 'station', render: wabtoolRenderCell },
			{
				data: 'confirmed',
				orderable: false,
				searchable: false,
			render: function(data, type) {
				if (type !== 'display') {
					return data || '';
				}
				if (!data) {
					return '<span class="text-muted">&mdash;</span>';
				}
				// one badge per letter: Q = QSL card, L = LoTW, E = eQSL, Z = QRZ.com, C = Clublog
				// (tooltip text is i18n'd by the view via the data-confirmlegend attribute)
				var legend = $('.scanresult').data('confirmlegend') || 'Q = QSL card, L = LoTW, E = eQSL, Z = QRZ.com, C = Clublog';
				var badges = data.split('').map(function(l) {
					return '<span class="badge bg-success">' + l + '</span>';
				}).join(' ');
				return '<span data-bs-toggle="tooltip" title="' + escapeHtml(legend) + '">' + badges + '</span>';
			}
			}
		],
		createdRow: function(row) {
			$(row).find('[data-bs-toggle="tooltip"]').tooltip();
		}
	});

	// Every ajax response updates counters and selection affordances
	wabtoolTable.on('xhr', function(e, settings, json) {
		if (!json || json.error) {
			return;
		}
		wabtoolRecordsFiltered = json.recordsFiltered || 0;

		// the apply button tracks the current filter on every response, not
		// just the one carrying the summary
		$('#applyWab').toggleClass('d-none', json.recordsTotal === 0);

		if (json.summary !== undefined) {
			if (json.recordsTotal > 0) {
				wabtoolRenderSummary(json.summary);
			} else if (wabtoolOnlyFull) {
				// everything was filtered out: keep the summary rendered so
				// the filter switch stays reachable
				wabtoolRenderSummary(json.summary);
				$('.wabtool-summary').prepend('<div class="alert alert-info mb-2">No 100% matching QSOs &mdash; switch off &quot;Only 100% matches&quot; to see all candidates.</div>');
			} else {
				$('.wabtool-summary').html('<div class="alert alert-info mb-2">No QSOs found that need a WAB square.</div>');
			}
		}
		wabtoolUpdateSelectAllButton();
	});

	wabtoolTable.on('draw', function() {
		wabtoolSyncHeaderCheckbox();
		$('#startScan').removeClass('running').prop('disabled', false);
	});

	wabtoolTable.on('error', function() {
		$('.wabtool-summary').html('<div class="alert alert-danger mb-2">An error occurred while processing the request.</div>');
		$('#startScan').removeClass('running').prop('disabled', false);
		$('#applyWab').addClass('d-none');
	});
}

function wabtoolRenderCell(data, type) {
	if (type !== 'display') {
		return data;
	}
	return escapeHtml(data);
}

function wabtoolRenderApplyResult(data) {
	if (!data || data.error) {
		$('.applyresult').html('<div class="alert alert-danger mb-0">' + ((data && data.error) ? escapeHtml(data.error) : 'An error occurred while processing the request.') + '</div>');
		return;
	}

	var squares = Object.keys(data.squares || {}).map(function(k) {
		return k + ' (' + data.squares[k] + ')';
	}).join(', ');

	var html = '<div class="alert alert-success mb-2">'
		+ '<strong>' + data.updated + '</strong> QSO(s) updated'
		+ (data.skipped ? ', <strong>' + data.skipped + '</strong> skipped' : '')
		+ (squares ? ' &mdash; ' + escapeHtml(squares) : '')
		+ '</div>';

	$('.applyresult').html(html);
}

function wabtoolStartScan(clearApplyResult) {
	$('#startScan').addClass('running').prop('disabled', true);
	if (clearApplyResult !== false) {
		$('.applyresult').html('');
	}
	$('#applyWab').addClass('d-none');

	// release the old table before its markup is wiped
	if (wabtoolTable !== null) {
		try { wabtoolTable.destroy(); } catch (e) { /* already gone */ }
		wabtoolTable = null;
	}
	$('.scanresult').html('');

	wabtoolSelected = {};
	wabtoolAllMatching = false;
	wabtoolOnlyFull = true; // the list opens on "only 100% matches"
	wabtoolRecordsFiltered = 0;
	wabtoolSummaryPending = true;

	wabtoolInitTable();
}

// Bind the WAB tool handlers (defined once, bound once jQuery is available)
function bindWabTool() {

	$('#startScan').on('click', function() {
		wabtoolStartScan();
	});

	$('#applyWab').on('click', function() {
		var payloadIds, count, searchData;
		if (wabtoolAllMatching) {
			// server enumerates the matching set; filters mirror the scan
			payloadIds = 'ALL';
			count = wabtoolRecordsFiltered;
			searchData = {
				ids: payloadIds,
				station_id: $('#de').val(),
				search: wabtoolTable !== null ? wabtoolTable.search() : ''
			};
			if (wabtoolOnlyFull) {
				searchData.only_full = 1; // mirror the table filter
			}
		} else {
			var ids = Object.keys(wabtoolSelected);
			if (!ids.length) {
				BootstrapDialog.show({
					type: BootstrapDialog.TYPE_WARNING,
					message: 'No QSOs selected.',
					buttons: [{ label: 'OK', action: function(d) { d.close(); } }]
				});
				return;
			}
			payloadIds = JSON.stringify(ids);
			count = ids.length;
			// One JSON string, not ids[]: array posts would exceed
			// max_input_vars and silently cause a partial apply
			searchData = { ids: payloadIds };
		}

		var btn = $('#applyWab');
		BootstrapDialog.confirm({
			title: btn.data('confirm-title'),
			message: btn.data('confirm-msg') + ' (' + count + ')',
			type: BootstrapDialog.TYPE_PRIMARY,
			closable: true,
			draggable: true,
			btnOKClass: 'btn-success',
			callback: function(result) {
				if (!result) {
					return;
				}
				btn.addClass('running').prop('disabled', true);
				$.ajax({
					url: site_url + '/wabtool/apply',
					type: 'POST',
					dataType: 'json',
					data: searchData,
					success: function(data) {
						wabtoolRenderApplyResult(data);
						// refresh the preview table, keep the result alert visible
						wabtoolStartScan(false);
					},
					error: function() {
						wabtoolRenderApplyResult(null);
					},
					complete: function() {
						btn.removeClass('running').prop('disabled', false);
					}
				});
			}
		});
	});

	// Row checkbox: unchecking while "select all matching" is active
	// leaves that mode (everything else would still be applied)
	$(document).on('change', '.wabtool-row', function() {
		var id = $(this).val();
		if (this.checked) {
			wabtoolSelected[id] = 1;
		} else {
			if (wabtoolAllMatching) {
				wabtoolAllMatching = false;
				wabtoolSelected = {};
				$('.wabtool-row').prop('checked', false);
			} else {
				delete wabtoolSelected[id];
			}
		}
		wabtoolSyncHeaderCheckbox();
		wabtoolUpdateSelectAllButton();
	});

	// Header checkbox toggles the selectable rows on the current page only
	$(document).on('change', '#wabtoolSelectAll', function() {
		var headerChecked = this.checked;
		$('#wabtoolTable tbody .wabtool-row:enabled').each(function() {
			$(this).prop('checked', headerChecked);
			var id = $(this).val();
			if (headerChecked) {
				wabtoolSelected[id] = 1;
			} else {
				delete wabtoolSelected[id];
			}
		});
		wabtoolUpdateSelectAllButton();
	});

	$(document).on('click', '#wabtoolSelectAllMatching', function() {
		wabtoolAllMatching = !wabtoolAllMatching;
		if (!wabtoolAllMatching) {
			wabtoolSelected = {};
		}
		$('.wabtool-row:enabled').prop('checked', wabtoolAllMatching);
		wabtoolSyncHeaderCheckbox();
		wabtoolUpdateSelectAllButton();
	});

	// "Only 100% matches": refetch without boundary-straddling grids;
	// select-all and the bulk apply follow the filter
	$(document).on('click', '#wabtoolOnlyFull', function() {
		wabtoolOnlyFull = !wabtoolOnlyFull;
		$(this).toggleClass('active', wabtoolOnlyFull).attr('aria-pressed', String(wabtoolOnlyFull));
		if (!wabtoolAllMatching) {
			// rows about to be hidden by the filter must not stay selected
			wabtoolSelected = {};
		}
		if (wabtoolTable !== null) {
			wabtoolTable.ajax.reload(); // reset paging: the row set changes
		}
	});

	// Map per preview row: full WAB map from wab.js, square highlighted
	$(document).on('click', '.wabtool-map', function(e) {
		e.preventDefault();
		var $a = $(this);
		showWabMapModal($a.attr('data-square') || null, $a.attr('data-call') || null, $a.attr('data-lat') || null, $a.attr('data-lng') || null);
	});

	// QSO detail dialog per callsign
	$(document).on('click', '.wabtool-qso', function(e) {
		e.preventDefault();
		displayQso($(this).attr('data-id'));
	});
}

// section scripts load after jQuery in the footer, so $ is always defined
$(function() {
	bindWabTool();
});
