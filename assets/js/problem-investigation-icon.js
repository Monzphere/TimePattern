(function() {
	'use strict';

	const INCIDENT_ACTION = 'incident.investigation.view';
	const ICON_CLASS = 'zi-search';

	function isProblemsContext() {
		return window.location.href.includes('action=problem.view') ||
			window.location.href.includes('action=widget.problems.view') ||
			document.getElementById('monitoring_problem_filter') !== null ||
			document.querySelector('form[name="problem"]') !== null ||
			document.querySelector('[data-page="problem"]') !== null ||
			document.querySelectorAll('.dashboard-grid-widget-contents.dashboard-widget-problems').length > 0;
	}

	function getProblemsTables() {
		const result = [];
		const seen = new Set();

		function add(table) {
			if (table && table.querySelector && !seen.has(table)) {
				seen.add(table);
				result.push(table);
			}
		}

		const flickerfree = document.querySelector('.flickerfreescreen');
		if (flickerfree) {
			flickerfree.querySelectorAll('table.list-table').forEach(add);
		}

		document.querySelectorAll('.dashboard-grid-widget-contents.dashboard-widget-problems').forEach(function(widget) {
			const table = widget.querySelector('table.list-table') ||
				(widget.tagName === 'TABLE' && widget.classList.contains('list-table') ? widget : null);
			if (table) add(table);
		});

		if (window.location.href.includes('action=widget.problems.view')) {
			const standalone = document.querySelector('table.list-table');
			if (standalone && standalone.querySelector('thead')) add(standalone);
		}

		const pageTable = document.querySelector('table.overflow-ellipsis') ||
			document.querySelector('form[name="problem"] table');
		if (pageTable) add(pageTable);

		const listTables = document.querySelectorAll('table.list-table');
		listTables.forEach(add);

		return result;
	}

	function getInvestigationUrl(eventid, triggerid) {
		const url = new URL('zabbix.php', window.location.origin);
		url.searchParams.set('action', INCIDENT_ACTION);
		url.searchParams.set('eventid', String(eventid));
		if (triggerid) {
			url.searchParams.set('triggerid', String(triggerid));
		}
		return url.pathname + url.search;
	}

	function createInvestigationIcon(eventid, triggerid) {
		const link = document.createElement('a');
		link.href = getInvestigationUrl(eventid, triggerid);
		link.className = 'btn-icon btn-icon-small ms-1 mnz-problem-investigation-icon';
		link.title = 'Incident Investigation';
		link.setAttribute('aria-label', 'Incident Investigation');
		link.style.cursor = 'pointer';
		link.style.display = 'inline-flex';
		link.style.alignItems = 'center';
		link.style.verticalAlign = 'middle';
		link.innerHTML = '<span class="' + ICON_CLASS + '" style="font-size: 12px;"></span>';
		return link;
	}

	function findProblemLinkAndIds(row) {
		const menuLinks = row.querySelectorAll('a.link-action[data-menu-popup]');
		for (let i = 0; i < menuLinks.length; i++) {
			const link = menuLinks[i];
			const attr = link.getAttribute('data-menu-popup');
			if (!attr) continue;
			try {
				const menu = JSON.parse(attr);
				if (menu && menu.type === 'trigger' && menu.data) {
					const eventid = menu.data.eventid ? String(menu.data.eventid) : null;
					const triggerid = menu.data.triggerid ? String(menu.data.triggerid) : null;
					return { link: link, eventid: eventid, triggerid: triggerid };
				}
			} catch (e) {}
		}
		return null;
	}

	function getIdsFromRow(row) {
		const found = findProblemLinkAndIds(row);
		if (found && found.eventid) return { eventid: found.eventid, triggerid: found.triggerid, problemLink: found.link };

		let eventid = null;
		let triggerid = null;
		const updateLink = row.querySelector('a[data-eventid]');
		if (updateLink) eventid = updateLink.getAttribute('data-eventid');
		if (!eventid) {
			const eventLink = row.querySelector('a[href*="eventid"]');
			if (eventLink && eventLink.href) {
				const match = eventLink.href.match(/eventid[s]?[=\[\]]*(\d+)/);
				if (match) eventid = match[1];
			}
		}
		if (!eventid) {
			const checkbox = row.querySelector('input[name^="eventids["]');
			if (checkbox) {
				const m = checkbox.getAttribute('name').match(/eventids\[(\d+)\]/);
				if (m) eventid = m[1];
			}
		}
		return { eventid: eventid, triggerid: triggerid, problemLink: null };
	}

	function getInsertCell(row, ids) {
		if (ids.problemLink) {
			const cell = ids.problemLink.closest('td');
			if (cell) return cell;
		}
		const actionsCell = row.querySelector('td.list-table-actions, td .list-table-actions');
		if (actionsCell) return actionsCell.closest('td') || actionsCell;
		return row.querySelector('td:last-child');
	}

	function injectIconsIntoTable(table) {
		const rows = table.querySelectorAll('tr:not(.timeline-axis):not(.timeline-td)');
		rows.forEach(function(row) {
			if (row.querySelector('.mnz-problem-investigation-icon')) return;

			const ids = getIdsFromRow(row);
			if (!ids.eventid) return;

			const insertCell = getInsertCell(row, ids);
			if (!insertCell) return;

			const alertIcon = insertCell.querySelector('.zi-alert-with-content');
			const icon = createInvestigationIcon(ids.eventid, ids.triggerid);
			const spacer = document.createTextNode('\u00A0');

			if (alertIcon && alertIcon.parentNode) {
				const parent = alertIcon.parentNode;
				const nextSibling = alertIcon.nextSibling;
				if (nextSibling) {
					parent.insertBefore(spacer, nextSibling);
					parent.insertBefore(icon, nextSibling);
				} else {
					parent.appendChild(spacer);
					parent.appendChild(icon);
				}
			} else {
				insertCell.appendChild(spacer);
				insertCell.appendChild(icon);
			}
		});
	}

	function injectIcons() {
		if (!isProblemsContext()) return;

		const tables = getProblemsTables();
		tables.forEach(injectIconsIntoTable);
	}

	function init() {
		injectIcons();

		const observer = new MutationObserver(function(mutations) {
			let shouldInject = false;
			for (const mut of mutations) {
				if (mut.addedNodes.length) {
					shouldInject = true;
					break;
				}
			}
			if (shouldInject) {
				setTimeout(injectIcons, 50);
			}
		});

		const target = document.querySelector('.wrapper') || document.body;
		if (target) {
			observer.observe(target, {
				childList: true,
				subtree: true
			});
		}

		if (typeof $ !== 'undefined') {
			$(document).on('complete.view', injectIcons);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
