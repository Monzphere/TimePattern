<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';

$this->addJsFile('gtlc.js');

$has_event = $data['has_event'] ?? false;
$event = $data['event'] ?? [];
$trigger = $data['trigger'] ?? null;
$host = $data['host'] ?? null;
$related_events = $data['related_events'] ?? [];
$six_months_events = $data['six_months_events'] ?? [];
$items = $data['items'] ?? [];
$monthly_comparison = $data['monthly_comparison'] ?? [];
$actions_data = $data['actions_data'] ?? ['result' => null, 'users' => [], 'mediatypes' => []];

$event_time = isset($event['clock']) ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']) : '';
$event_date = isset($event['clock']) ? zbx_date2str('Y-m-d', $event['clock']) : '';
$time_ago = isset($event['clock']) ? zbx_date2age($event['clock']) : '';
$mttr_seconds = 0;
$mttr_count = 0;
foreach ($data['six_months_events'] ?? [] as $ev) {
	if (!empty($ev['r_clock']) && (int)$ev['r_clock'] > 0) {
		$mttr_seconds += ((int)$ev['r_clock'] - (int)$ev['clock']);
		$mttr_count++;
	}
}
$mttr_formatted = $mttr_count > 0 ? zbx_date2age(0, (int)round($mttr_seconds / $mttr_count)) : null;
$severity = (int)($event['severity'] ?? 0);
$severity_name = CSeverityHelper::getName($severity);
$severity_color = CSeverityHelper::getColor($severity);

$html_page = (new CHtmlPage())
	->setTitle(_('Incident Investigation'))
	->setDocUrl('')
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CButton('export_report', _('Export report')))
						->addClass(ZBX_STYLE_BTN_ALT)
						->setAttribute('onclick', "window.print(); return false;")
				)
				->addItem(
					(new CButton('back_problems', _('Back to Problems')))
						->setAttribute('onclick', "location.href='zabbix.php?action=problem.view'")
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

if (!$has_event) {
	$form = (new CForm('get'))
		->setName('mnz-incident-investigation-form')
		->setAttribute('aria-label', _('Event lookup'));
	$form->addItem((new CInput('hidden', 'action', 'incident.investigation.view'))->removeId());
	$form->addItem(
		(new CFormGrid())
			->addItem([
				new CLabel(_('Event ID'), 'eventid'),
				new CFormField(
					(new CTextBox('eventid', $data['eventid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Enter event ID'))
						->setAttribute('autofocus', 'autofocus')
				)
			])
			->addItem([
				new CLabel(_('Trigger ID'), 'triggerid'),
				new CFormField(
					(new CTextBox('triggerid', $data['triggerid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Optional'))
				)
			])
			->addItem([
				new CLabel(_('Host ID'), 'hostid'),
				new CFormField(
					(new CTextBox('hostid', $data['hostid'] ?? ''))
						->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
						->setAttribute('placeholder', _('Optional'))
				)
			])
	);
	$form->addItem(
		(new CDiv([
			(new CSubmit('investigate', _('Investigate')))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mnz-incident-investigation-form-actions')
	);
	$help = (new CDiv(_('Enter the event ID from Monitoring → Problems. Trigger ID and Host ID are optional and can be extracted from the event.')))
		->addClass('mnz-incident-investigation-help');
	$html_page->addItem(
		(new CDiv([$form, $help]))->addClass('mnz-incident-investigation-empty')
	);
	$html_page->show();
	return;
}

$content = new CDiv();
$content->addClass('mnz-incident-investigation-wrapper');

$meta_parts = [
	(new CSpan($severity_name))->addClass(CSeverityHelper::getStyle($severity))->addClass('mnz-incident-severity'),
	new CSpan(' • '),
	new CSpan($host ? ($host['name'] ?? $host['host'] ?? '') : 'N/A'),
	new CSpan(' • '),
	new CSpan($event_time),
	new CSpan(' • '),
	new CSpan($time_ago)
];
if ($mttr_formatted !== null) {
	$meta_parts[] = new CSpan(' • ');
	$meta_parts[] = (new CSpan(_s('MTTR: %1$s', $mttr_formatted)))->addClass('mnz-incident-mttr');
}
$event_header = (new CDiv([
	(new CTag('h2', true, $event['name'] ?? _('Unknown event')))->addClass('mnz-incident-investigation-title'),
	(new CDiv($meta_parts))->addClass('mnz-incident-investigation-meta')
]))->addClass('mnz-incident-investigation-header');
$content->addItem($event_header);

$sections = new CDiv();
$sections->addClass('mnz-incident-investigation-sections');

$host_groups = $host ? implode(', ', array_column($host['hostgroups'] ?? [], 'name')) : '';

$pattern_events = !empty($six_months_events) ? $six_months_events : $related_events;
$hourly_data = array_fill(0, 24, 0);
$weekly_data = array_fill(0, 7, 0);
$monthly_data = [];
$weekdays = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
$user_lang = (CWebUser::$data && isset(CWebUser::$data['lang'])) ? CWebUser::$data['lang'] : null;
$force_24h = in_array($user_lang, ['pt_BR', 'pt_PT']);
$use_12h = !$force_24h && (strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false);
$hour_labels = [];
for ($h = 0; $h < 24; $h++) {
	$ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
	$hour_labels[] = $use_12h ? zbx_date2str('g a', $ts_utc, 'UTC') : (zbx_date2str('H', $ts_utc, 'UTC') . _x('h', 'hour short'));
}

$weekly_hourly_details = array_fill(0, 7, array_fill(0, 24, 0));
$monthly_daily_details = [];
$month_keys = [];

$current_time = time();
for ($i = 11; $i >= 0; $i--) {
	$ts = strtotime("-$i months", $current_time);
	$mk = date('Y-m', $ts);
	$month_keys[] = $mk;
	$days_in_month = (int)date('t', $ts);
	$monthly_daily_details[$mk] = array_fill(0, 31, 0);
}

foreach ($pattern_events as $e) {
	$h = (int)date('G', $e['clock']);
	$w = (int)date('w', $e['clock']);
	$mk = date('Y-m', $e['clock']);
	$day = (int)date('j', $e['clock']);

	$hourly_data[$h]++;
	$weekly_data[$w]++;
	$monthly_data[$mk] = ($monthly_data[$mk] ?? 0) + 1;

	$weekly_hourly_details[$w][$h]++;
	if (isset($monthly_daily_details[$mk])) {
		$monthly_daily_details[$mk][$day - 1]++;
	}
}

$monthly_labels = [];
$monthly_values = [];
$monthly_drilldown = [];
foreach ($month_keys as $mk) {
	$ts = strtotime($mk . '-01');
	$monthly_labels[] = zbx_date2str('M/y', $ts);
	$monthly_values[] = $monthly_data[$mk] ?? 0;
	$monthly_drilldown[] = array_values($monthly_daily_details[$mk] ?? array_fill(0, 31, 0));
}

$total_events = count($pattern_events);
$weekday_names_short = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
$peak_day = 0;
$peak_hour = 0;
$peak_count = 0;
for ($w = 0; $w < 7; $w++) {
	for ($h = 0; $h < 24; $h++) {
		$c = $weekly_hourly_details[$w][$h] ?? 0;
		if ($c > $peak_count) {
			$peak_count = $c;
			$peak_day = $w;
			$peak_hour = $h;
		}
	}
}
$current_month_count = $monthly_comparison['current_month']['count'] ?? 0;
$month_change = $monthly_comparison['change_percentage'] ?? 0;
$critical_hours = [];
$running = 0;
$hourly_sorted = $hourly_data;
arsort($hourly_sorted);
$target = $total_events > 0 ? $total_events * 0.4 : 0;
foreach ($hourly_sorted as $h => $cnt) {
	if ($running >= $target) break;
	$running += $cnt;
	$ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
	$critical_hours[] = $use_12h ? zbx_date2str('g a', $ts_utc, 'UTC') : zbx_date2str('H:i', $ts_utc, 'UTC');
}
sort($critical_hours);
$critical_slot_label = count($critical_hours) === 0 ? '-'
	: (count($critical_hours) <= 3 ? implode(', ', $critical_hours) : (($critical_hours[0] ?? '') . ' - ' . end($critical_hours)));
$slot_count = 7 * 24;
$avg_per_slot = $total_events > 0 ? $total_events / $slot_count : 0;
$avg_per_hour = $total_events > 0 ? $total_events / 24 : 0;
$avg_per_weekday = $total_events > 0 ? $total_events / 7 : 0;
$avg_per_month = $total_events > 0 && count($month_keys) > 0 ? $total_events / count($month_keys) : 0;

$recommendations = [];
if ($peak_count > 0 && $peak_day >= 1 && $peak_day <= 5) {
	$recommendations[] = _('Incidents on weekdays — possible correlation with business load.');
}
if ($peak_count > 0 && ($peak_day === 0 || $peak_day === 6)) {
	$recommendations[] = _('Weekend pattern — check automated or batch processes.');
}
if ($total_events > 0 && count($critical_hours) > 0) {
	$recommendations[] = _('Schedule maintenance outside critical hours.');
}
if ($month_change > 10) {
	$recommendations[] = _('Increasing trend — proactive review recommended.');
}
if (empty($recommendations)) {
	$recommendations[] = _('Use the heatmap to find optimal maintenance windows.');
}

$peak_val = $peak_count > 0 ? ($weekday_names_short[$peak_day] . ' ' . ($use_12h ? zbx_date2str('g a', gmmktime($peak_hour, 0, 0, 1, 1, 2024), 'UTC') : zbx_date2str('H:i', gmmktime($peak_hour, 0, 0, 1, 1, 2024), 'UTC'))) : '-';
$critical_val = $total_events > 0 ? $critical_slot_label : '-';
$key_insight_action = !empty($recommendations) ? $recommendations[0] : null;

$insight_chunks = [];
if ($host_groups !== '') {
	$insight_chunks[] = (new CDiv([
		(new CTag('span', true, _('Groups')))->addClass('mnz-key-chip-label'),
		(new CTag('span', true, $host_groups))->addClass('mnz-key-chip-value mnz-key-chip-groups')
	]))->addClass('mnz-key-chip');
}
$insight_chunks[] = (new CDiv([
	(new CTag('span', true, _('Peak')))->addClass('mnz-key-chip-label'),
	(new CTag('span', true, $peak_val))->addClass('mnz-key-chip-value')
]))->addClass('mnz-key-chip');
$insight_chunks[] = (new CDiv([
	(new CTag('span', true, _('This month')))->addClass('mnz-key-chip-label'),
	(new CDiv([
		(new CTag('span', true, (string)$current_month_count))->addClass('mnz-key-chip-value'),
		$month_change != 0
			? (new CSpan(($month_change > 0 ? ' ↑' : ' ↓') . abs($month_change) . '%'))
				->addClass('mnz-key-chip-change')
				->addStyle('color:' . ($month_change > 0 ? '#e74c3c' : '#27ae60'))
			: null
	]))->addClass('mnz-key-chip-value-row')
]))->addClass('mnz-key-chip');
$trend_label = '';
if ($month_change > 0) {
	$trend_label = '↗ ' . _s('+%1$s%% vs last month', (string)(int)$month_change);
} elseif ($month_change < 0) {
	$trend_label = '↘ ' . _s('%1$s%% vs last month', (string)(int)$month_change);
} else {
	$trend_label = '→ ' . _('Stable');
}
$insight_chunks[] = (new CDiv([
	(new CTag('span', true, _('Trend')))->addClass('mnz-key-chip-label'),
	(new CTag('span', true, $trend_label))->addClass('mnz-key-chip-value')->addStyle('color:' . ($month_change > 0 ? '#e74c3c' : ($month_change < 0 ? '#27ae60' : '#6c757d')))
]))->addClass('mnz-key-chip');
$insight_chunks[] = (new CDiv([
	(new CTag('span', true, _('Critical 40%')))->addClass('mnz-key-chip-label'),
	(new CTag('span', true, $critical_val))->addClass('mnz-key-chip-value')
]))->addClass('mnz-key-chip');
if ($trigger && !empty($trigger['expression'])) {
	$expr = $trigger['expression'];
	$expr_short = strlen($expr) > 50 ? substr($expr, 0, 47) . '...' : $expr;
	$insight_chunks[] = (new CDiv([
		(new CTag('span', true, _('Trigger')))->addClass('mnz-key-chip-label'),
		(new CTag('span', true, $expr_short))->addClass('mnz-key-chip-value mnz-key-chip-trigger')->setAttribute('title', $expr)
	]))->addClass('mnz-key-chip');
}

$key_insight_body = [(new CDiv($insight_chunks))->addClass('mnz-key-insight-chips')];
if ($key_insight_action) {
	$key_insight_body[] = (new CDiv([
		(new CTag('span', true, _('Action') . ': '))->addClass('mnz-key-chip-action-label'),
		(new CTag('span', true, $key_insight_action))->addClass('mnz-key-chip-action-text')
	]))->addClass('mnz-key-chip-action');
}

$key_insight_section = (new CDiv($key_insight_body))->addClass('mnz-incident-section mnz-incident-section-key-insight');

$monthly_chart = (new CDiv())->setId('mnz-investigation-monthly-chart')->addClass('mnz-incident-chart mnz-incident-chart-monthly');
$monthly_drilldown = (new CDiv())
	->setId('mnz-monthly-drilldown')
	->addClass('mnz-investigation-drilldown')
	->addItem((new CDiv(_('Click a month to see which days had the most incidents')))->addClass('mnz-drilldown-hint')->addClass('mnz-drilldown-hint-monthly'));
$monthly_container = (new CDiv([
	(new CTag('h4', true, _('Last 12 months')))->addClass('mnz-incident-chart-title'),
	$monthly_chart,
	$monthly_drilldown
]))->addClass('mnz-incident-chart-container');

$filter_bar = (new CDiv())->setId('mnz-investigation-filter-bar')->addClass('mnz-investigation-filter-bar');
$heatmap_container = (new CDiv())
	->setId('mnz-investigation-heatmap')
	->addClass('mnz-heatmap-container');
$sla_service_container = (new CDiv())->setId('mnz-sla-service-container')->addClass('mnz-sla-service-container');
$heatmap_section = (new CDiv([
	(new CTag('h3', true, _('Incident heatmap (day × hour)')))->addClass('mnz-incident-section-title'),
	(new CDiv(_('Click a cell to filter all charts')))->addClass('mnz-drilldown-hint'),
	$heatmap_container
]))->addClass('mnz-incident-section mnz-incident-section-heatmap');

$sla_panel = (new CDiv([
	(new CDiv())->setId('mnz-sla-gauge-wrap')->addClass('mnz-sla-gauge-wrap'),
	$sla_service_container
]))->addClass('mnz-sla-panel');

$sla_section = (new CDiv([
	(new CTag('h3', true, _('SLA by Service')))->addClass('mnz-incident-section-title'),
	(new CDiv(_('Current SLI and services affected')))->addClass('mnz-drilldown-hint'),
	$sla_panel
]))->addClass('mnz-incident-section mnz-incident-section-sla');

$heatmap_sla_row = (new CDiv([
	$heatmap_section,
	$sla_section
]))->addClass('mnz-heatmap-sla-row');

$time_patterns_section = (new CDiv([
	(new CTag('h3', true, _('Time patterns')))->addClass('mnz-incident-section-title'),
	$filter_bar,
	$heatmap_sla_row,
	$monthly_container
]))->addClass('mnz-incident-section mnz-incident-section-patterns');

$sections->addItem($key_insight_section);
$sections->addItem($time_patterns_section);

if (($related_events && $trigger) || ($actions_data['result'] && !empty($actions_data['result']['actions']))) {
	$allowed = [
		'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
		'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
		'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
		'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
		'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS) && ($trigger && ($trigger['manual_close'] ?? 0) == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED),
		'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
	];
	$timeline_table = ($related_events && $trigger)
		? make_small_eventlist($event, $allowed)
		: (new CTableInfo())->addRow([(new CCol(_('No event list available')))->setColSpan(7)->addClass('mnz-incident-empty-hint')]);
	$actions_table = ($actions_data['result'] && !empty($actions_data['result']['actions']))
		? makeEventDetailsActionsTable($actions_data['result'], $actions_data['users'], $actions_data['mediatypes'])
		: (new CTableInfo())->setHeader([_('Step'), _('Time'), _('User/Recipient'), _('Action'), _('Message/Command'), _('Status'), _('Info')])
			->addRow([(new CCol(_('No actions for this event')))->setColSpan(7)->addClass('mnz-incident-empty-hint')]);
	$default_view = ($related_events && $trigger) ? 'timeline' : 'actions';
	$btn_timeline = (new CButton('mnz-view-timeline', _('Timeline')))
		->addClass('radio-switch')
		->setAttribute('data-view', 'timeline');
	$btn_actions = (new CButton('mnz-view-actions', _('Actions')))
		->addClass('radio-switch')
		->setAttribute('data-view', 'actions');
	if ($default_view === 'timeline') {
		$btn_timeline->addClass(ZBX_STYLE_PAGING_SELECTED);
	} else {
		$btn_actions->addClass(ZBX_STYLE_PAGING_SELECTED);
	}
	$toggle_container = (new CDiv([$btn_timeline, $btn_actions]))
		->addClass(ZBX_STYLE_PAGING_BTN_CONTAINER);
	$header_row = (new CDiv([
		(new CTag('h3', true, _('Timeline')))->addClass('mnz-incident-section-title'),
		$toggle_container
	]))->addClass('mnz-incident-section-header-row');
	$timeline_wrap = (new CDiv($timeline_table))
		->setId('mnz-timeline-view')
		->addClass('mnz-timeline-actions-content')
		->addStyle($default_view === 'timeline' ? '' : 'display: none;');
	$actions_wrap = (new CDiv($actions_table))
		->setId('mnz-actions-view')
		->addClass('mnz-timeline-actions-content')
		->addStyle($default_view === 'actions' ? '' : 'display: none;');
	$timeline_section = (new CDiv([
		$header_row,
		$timeline_wrap,
		$actions_wrap
	]))->addClass('mnz-incident-section mnz-incident-section-timeline');
	$sections->addItem($timeline_section);
}

if ($items && isset($event['clock'])) {
	$event_ts = $event['clock'];
	$from_ts = $event_ts - 21600;
	$from_time = date('Y-m-d H:i:s', $from_ts);
	$to_time = 'now';
	$itemids = array_column($items, 'itemid');
	$base_params = [
		'from' => $from_time,
		'to' => $to_time,
		'type' => 0,
		'resolve_macros' => 1,
		'width' => 800,
		'height' => 250,
		'_' => time()
	];
	$chart_url = 'chart.php?' . http_build_query($base_params);
	foreach ($itemids as $itemid) {
		$chart_url .= '&itemids[]=' . urlencode($itemid);
	}
	$graphs_section = (new CDiv([
		(new CTag('h3', true, _('Graphs (6h before incident)')))->addClass('mnz-incident-section-title'),
		(new CDiv(
			(new CTag('img', true))
				->setAttribute('src', $chart_url)
				->setAttribute('alt', _('Problem graph'))
				->addClass('mnz-incident-chart-image')
		))->addClass('mnz-incident-graph-wrapper')
	]))->addClass('mnz-incident-section mnz-incident-section-graphs');
	$sections->addItem($graphs_section);
}

$content->addItem($sections);

$event_clocks = array_column($pattern_events, 'clock');
$hostid_for_sla = ($host && isset($host['hostid'])) ? $host['hostid'] : null;
$chart_data = [
	'hostid' => $hostid_for_sla,
	'hourly' => array_values($hourly_data),
	'weekly' => array_values($weekly_data),
	'monthly' => $monthly_values,
	'hourLabels' => $hour_labels,
	'weekLabels' => $weekdays,
	'monthLabels' => $monthly_labels,
	'weeklyHourlyDetails' => array_map(function($arr) { return array_values($arr); }, $weekly_hourly_details),
	'monthlyDailyDetails' => $monthly_drilldown,
	'monthKeys' => $month_keys,
	'dayLabels' => range(1, 31),
	'eventClocks' => $event_clocks,
	'avgPerSlot' => $avg_per_slot,
	'avgPerHour' => $avg_per_hour,
	'avgPerWeekday' => $avg_per_weekday,
	'avgPerMonth' => $avg_per_month,
	'hintWeek' => _('Click a day to see which hours had the most incidents'),
	'hintMonth' => _('Click a month to see which days had the most incidents'),
	'hintDay' => _('Click a day to see hourly distribution in heatmap'),
	'hintHour' => _('Click an hour to filter all charts'),
	'filteredBy' => _('Filtered by'),
	'clearFilter' => _('Clear filter'),
	'aboveAvg' => _('above avg'),
	'times' => _('×'),
	'sameSlotLastWeek' => _('Same slot last week'),
	'sameDayLastWeek' => _('Same day last week'),
	'incidents' => _('incidents')
];

$chart_script = 'window.mnzInvestigationData = '.json_encode($chart_data).';'.
'jQuery(document).ready(function() {'.
	'var d = window.mnzInvestigationData; if (!d) return;'.
	'var clocks = (d.eventClocks || []).map(function(t){ return parseInt(t,10); }).filter(function(t){ return !isNaN(t); });'.
	'var monthKeys = d.monthKeys || [];'.
	'var isDark = document.body.getAttribute("theme") === "dark-theme" || document.documentElement.getAttribute("theme") === "dark-theme";'.
	'var barBg = isDark ? "#3a3a3a" : "#e9ecef"; var barFill = "#0275b8";'.
	'var tc = isDark ? "#e0e0e0" : "#333333"; var lc = isDark ? "#999999" : "#666666";'.
	'function computeAggregates(clkList) {'.
		'var hourly = []; for (var h=0; h<24; h++) hourly[h]=0;'.
		'var weekly = []; for (var w=0; w<7; w++) weekly[w]=0;'.
		'var weeklyHourly = []; for (var w=0; w<7; w++) { weeklyHourly[w]=[]; for (var h=0; h<24; h++) weeklyHourly[w][h]=0; }'.
		'var monthly = {}; var monthlyDaily = {};'.
		'for (var m=0; m<monthKeys.length; m++) { monthly[monthKeys[m]]=0; monthlyDaily[monthKeys[m]]=[]; for (var d=0; d<31; d++) monthlyDaily[monthKeys[m]][d]=0; }'.
		'for (var i=0; i<clkList.length; i++) {'.
			'var t = clkList[i]; var dt = new Date(t*1000);'.
			'var h = dt.getHours(); var w = dt.getDay();'.
			'var ym = dt.getFullYear()+"-"+(String(dt.getMonth()+1).padStart(2,"0")); var day = dt.getDate()-1;'.
			'hourly[h]++; weekly[w]++;'.
			'if (weeklyHourly[w]) weeklyHourly[w][h]++;'.
			'if (monthly[ym]!==undefined) { monthly[ym]++; if (monthlyDaily[ym] && day>=0 && day<31) monthlyDaily[ym][day]++; }'.
		'}'.
		'var monthVals = []; var monthDrill = [];'.
		'for (var m=0; m<monthKeys.length; m++) {'.
			'var mk = monthKeys[m]; monthVals.push(monthly[mk]||0); monthDrill.push(monthlyDaily[mk] ? monthlyDaily[mk].slice(0,31) : []);'.
		'}'.
		'return { hourly: hourly, weekly: weekly, monthly: monthVals, weeklyHourlyDetails: weeklyHourly.map(function(arr){ return arr.slice(0,24); }), monthlyDailyDetails: monthDrill };'.
	'}'.
	'var currentFilter = null; var currentAgg = null;'.
	'var avgH = (d.avgPerHour && d.avgPerHour > 0) ? d.avgPerHour : 0; var avgW = (d.avgPerWeekday && d.avgPerWeekday > 0) ? d.avgPerWeekday : 0; var avgM = (d.avgPerMonth && d.avgPerMonth > 0) ? d.avgPerMonth : 0; var avgSlot = (d.avgPerSlot && d.avgPerSlot > 0) ? d.avgPerSlot : 0; var timesStr = d.times || "x";'.
	'function renderBar(elId, data, labels, color, clickable, dataType) {'.
		'var el = document.getElementById(elId); if (!el) return; var avg = (dataType==="hourly"?avgH : (dataType==="weekly"?avgW : avgSlot));'.
		'var max = Math.max.apply(Math, data); if (max === 0) max = 1;'.
		'var html = \'<div class="mnz-investigation-bars">\';'.
		'for (var i = 0; i < data.length; i++) {'.
			'var h = (data[i] / max) * 80;'.
			'var cls = clickable && data[i] > 0 ? " mnz-bar-clickable" : "";'.
			'var idx = clickable ? \' data-index="\'+i+\'" data-type="\'+dataType+\'"\' : "";'.
			'var comp = ""; if (avg > 0 && data[i] > avg * 1.2) { var mult = (data[i]/avg).toFixed(1); comp = \' <span class="mnz-bar-compare">\'+mult+timesStr+\'</span>\'; }'.
			'html += \'<div class="mnz-investigation-bar-item\'+cls+\'"\'+idx+\' title="\'+labels[i]+\': \'+data[i]+\'"><div class="mnz-investigation-bar" style="height:\'+Math.max(h,4)+\'px;background:\'+(data[i]>0?color:barBg)+\'"></div><span class="mnz-investigation-bar-label">\'+labels[i]+comp+\'</span></div>\';'.
		'}'.
		'html += \'</div>\'; el.innerHTML = html;'.
	'}'.
	'function renderHeatmap(agg) {'.
		'var el = document.getElementById("mnz-investigation-heatmap"); if (!el) return;'.
		'var wh = agg.weeklyHourlyDetails || []; var maxVal = 0; for (var w=0; w<7; w++) for (var h=0; h<24; h++) if (wh[w]&&wh[w][h]>maxVal) maxVal=wh[w][h]; if (maxVal===0) maxVal=1;'.
		'var html = \'<div class="mnz-heatmap-grid"><div class="mnz-heatmap-labels-col"><div class="mnz-heatmap-corner"></div>\';'.
		'for (var w=0; w<7; w++) html += \'<div class="mnz-heatmap-row-label">\'+(d.weekLabels[w]||"")+\'</div>\';'.
		'html += \'</div><div class="mnz-heatmap-body"><div class="mnz-heatmap-hours-row">\';'.
		'for (var h=0; h<24; h++) html += \'<div class="mnz-heatmap-hour-label">\'+(d.hourLabels[h]||h)+\'</div>\';'.
		'html += \'</div>\';'.
		'for (var w=0; w<7; w++) { html += \'<div class="mnz-heatmap-row">\'; for (var h=0; h<24; h++) { var v = (wh[w]&&wh[w][h])||0; var intensity = v/maxVal; var col = intensity>0 ? (intensity>0.5 ? (intensity>0.8 ? "#c0392b" : "#e67e22") : "#27ae60") : barBg; var cls = v>0 ? " mnz-heatmap-cell-active" : ""; html += \'<div class="mnz-heatmap-cell\'+cls+\'" data-w="\'+w+\'" data-h="\'+h+\'" style="background:\'+col+\'" title="\'+(d.weekLabels[w]||"")+\' \'+(d.hourLabels[h]||h)+\': \'+v+\'">\'+v+\'</div>\'; } html += \'</div>\'; }'.
		'html += \'</div></div>\'; el.innerHTML = html;'.
		'jQuery("#mnz-investigation-heatmap").off("click", ".mnz-heatmap-cell-active").on("click", ".mnz-heatmap-cell-active", function() {'.
			'var center = jQuery(this); var w = parseInt(center.data("w"),10); var h = parseInt(center.data("h"),10); var filtered = clocks.filter(function(t){ var dt=new Date(t*1000); return dt.getDay()===w && dt.getHours()===h; });'.
			'currentFilter = {type:"heatmap", weekday:w, hour:h}; currentAgg = computeAggregates(filtered); jQuery("#mnz-weekly-drilldown, #mnz-monthly-drilldown").removeClass("mnz-drilldown-visible"); applyAndRender(); updateFilterBar();'.
		'});'.
	'}'.
	'function renderMonthly(elId, data, labels) {'.
		'var el = document.getElementById(elId); if (!el) return;'.
		'var max = Math.max.apply(Math, data); if (max === 0) max = 1;'.
		'var html = \'<div class="mnz-investigation-monthly-bars">\';'.
		'for (var i = 0; i < data.length; i++) {'.
			'var h = (data[i] / max) * 100; var col = data[i] > 0 ? (i === data.length - 1 ? "#ff9800" : barFill) : barBg; var cls = data[i] > 0 ? " mnz-bar-clickable" : "";'.
			'var comp = ""; if (avgM > 0 && data[i] > avgM * 1.2) { var mult = (data[i]/avgM).toFixed(1); comp = \' <span class="mnz-bar-compare">\'+mult+timesStr+\'</span>\'; }'.
			'html += \'<div class="mnz-investigation-monthly-item\'+cls+\'" data-index="\'+i+\'" data-type="monthly" title="\'+labels[i]+\': \'+data[i]+\'"><div style="font-size:11px;font-weight:bold;color:\'+tc+\'">\'+data[i]+comp+\'</div><div class="mnz-investigation-monthly-bar" style="height:\'+Math.max(h,4)+\'px;background:\'+col+\'"></div><span class="mnz-investigation-monthly-label" style="color:\'+lc+\'">\'+labels[i]+\'</span></div>\';'.
		'}'.
		'html += \'</div>\'; el.innerHTML = html;'.
	'}'.
	'function renderDrilldown(containerId, data, labels, title, barColor, isDayDrilldown) {'.
		'var c = document.getElementById(containerId); if (!c) return;'.
		'var max = Math.max.apply(Math, data); if (max === 0) max = 1;'.
		'var hintDay = (isDayDrilldown && d.hintDay) ? \'<div class="mnz-drilldown-subhint">\'+d.hintDay+\'</div>\' : "";'.
		'var html = \'<div class="mnz-drilldown-content"><div class="mnz-drilldown-title">\'+title+\'</div>\'+hintDay+\'<div class="mnz-drilldown-chart"><div class="mnz-investigation-bars">\';'.
		'for (var i = 0; i < data.length; i++) {'.
			'var h = (data[i] / max) * 80; var day = i+1;'.
			'var cls = (isDayDrilldown && data[i]>0) ? " mnz-drilldown-day-bar mnz-bar-clickable" : ""; var dday = (isDayDrilldown && data[i]>0) ? \' data-day="\'+day+\'"\' : "";'.
			'var valSpan = \'<span class="mnz-drilldown-bar-value">\'+data[i]+\'</span>\';'.
			'html += \'<div class="mnz-investigation-bar-item mnz-drilldown-bar-item\'+cls+\'"\'+dday+\' title="\'+labels[i]+\': \'+data[i]+\'">\'+valSpan+\'<div class="mnz-investigation-bar" style="height:\'+Math.max(h,4)+\'px;background:\'+(data[i]>0?barColor:barBg)+\'"></div><span class="mnz-investigation-bar-label">\'+labels[i]+\'</span></div>\';'.
		'}'.
		'html += \'</div></div><button type="button" class="btn btn-alt mnz-drilldown-close">\'+("'. _('Close') .'")+\'</button></div>\';'.
		'c.innerHTML = html; c.classList.add("mnz-drilldown-visible");'.
	'}'.
	'function setupCloseHandler(containerId, hint, hintClass) {'.
		'hintClass = hintClass || "";'.
		'jQuery("#"+containerId+" .mnz-drilldown-close").off("click").on("click", function(){ var c=jQuery("#"+containerId); c.removeClass("mnz-drilldown-visible").empty(); c.append(\'<div class="mnz-drilldown-hint \'+hintClass+\'">\'+hint+\'</div>\'); });'.
	'}'.
	'function countSameSlotLastWeek(weekday, hour) {'.
		'var now = Math.floor(Date.now()/1000); var weekSec = 604800; var lastWeekEnd = now - weekSec; var lastWeekStart = lastWeekEnd - weekSec;'.
		'var n = 0; for (var i=0; i<clocks.length; i++) { var t = clocks[i]; if (t >= lastWeekStart && t < lastWeekEnd) { var dt = new Date(t*1000); if (dt.getDay()===weekday && dt.getHours()===hour) n++; } } return n;'.
	'}'.
	'function countSameDayLastWeek(weekday) {'.
		'var now = Math.floor(Date.now()/1000); var weekSec = 604800; var lastWeekEnd = now - weekSec; var lastWeekStart = lastWeekEnd - weekSec;'.
		'var n = 0; for (var i=0; i<clocks.length; i++) { var t = clocks[i]; if (t >= lastWeekStart && t < lastWeekEnd) { var dt = new Date(t*1000); if (dt.getDay()===weekday) n++; } } return n;'.
	'}'.
	'function updateFilterBar() {'.
		'var fb = document.getElementById("mnz-investigation-filter-bar"); if (!fb) return;'.
		'if (!currentFilter) { fb.innerHTML = ""; fb.classList.remove("mnz-filter-active"); return; }'.
		'var lbl = ""; var compHtml = "";'.
		'if (currentFilter.type==="hour") lbl = (d.hourLabels||[])[currentFilter.value] || currentFilter.value+"h";'.
		'else if (currentFilter.type==="weekday") lbl = (d.weekLabels||[])[currentFilter.value] || "";'.
		'else if (currentFilter.type==="month") lbl = (d.monthLabels||[])[currentFilter.value] || "";'.
		'else if (currentFilter.type==="day") lbl = (currentFilter.dateStr || "") + " (" + ((d.weekLabels||[])[currentFilter.weekday] || "") + ")";'.
		'else if (currentFilter.type==="heatmap") { lbl = (d.weekLabels||[])[currentFilter.weekday] + " " + ((d.hourLabels||[])[currentFilter.hour] || currentFilter.hour+"h"); var lastWeek = countSameSlotLastWeek(currentFilter.weekday, currentFilter.hour); compHtml = \' <span class="mnz-filter-comparison">\'+(d.sameSlotLastWeek||"")+\': \'+lastWeek+\' \'+(d.incidents||"incidents")+\'</span>\'; }'.
		'else if (currentFilter.type==="day" && currentFilter.weekday != null) { var lastWeek = countSameDayLastWeek(currentFilter.weekday); compHtml = \' <span class="mnz-filter-comparison">\'+(d.sameDayLastWeek||"")+\': \'+lastWeek+\' \'+(d.incidents||"incidents")+\'</span>\'; }'.
		'fb.classList.add("mnz-filter-active");'.
		'fb.innerHTML = \'<span class="mnz-filter-label">\'+(d.filteredBy||"")+\': \'+lbl+\'</span>\'+compHtml+\' <button type="button" class="btn btn-alt mnz-filter-clear">\'+(d.clearFilter||"")+\'</button>\';'.
		'jQuery("#mnz-investigation-filter-bar .mnz-filter-clear").off("click").on("click", function(){ currentFilter=null; currentAgg=computeAggregates(clocks); var m=jQuery("#mnz-monthly-drilldown"); m.removeClass("mnz-drilldown-visible").empty().append(\'<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">\'+(d.hintMonth||"")+\'</div>\'); applyAndRender(); updateFilterBar(); });'.
	'}'.
	'function applyAndRender() {'.
		'currentAgg = currentAgg || computeAggregates(clocks);'.
		'renderHeatmap(currentAgg);'.
		'renderMonthly("mnz-investigation-monthly-chart", currentAgg.monthly, d.monthLabels);'.
		'var dayLabs = (d.dayLabels&&d.dayLabels.length) ? d.dayLabels : []; while(dayLabs.length<31) dayLabs.push(dayLabs.length+1);'.
		'jQuery("#mnz-investigation-monthly-chart").off("click", ".mnz-bar-clickable").on("click", ".mnz-bar-clickable", function() {'.
			'var idx = parseInt(jQuery(this).data("index"), 10); var mk = monthKeys[idx]; if (!mk) return;'.
			'var filtered = clocks.filter(function(t){ var dt=new Date(t*1000); return dt.getFullYear()+"-"+(String(dt.getMonth()+1).padStart(2,"0"))===mk; });'.
			'currentFilter = {type:"month", value:idx}; currentAgg = computeAggregates(filtered); jQuery("#mnz-weekly-drilldown").removeClass("mnz-drilldown-visible"); applyAndRender(); updateFilterBar();'.
			'var dd = currentAgg.monthlyDailyDetails[idx]; if (dd) renderDrilldown("mnz-monthly-drilldown", dd, dayLabs, "'. _('Daily distribution') .' - "+d.monthLabels[idx], barFill, true); setupCloseHandler("mnz-monthly-drilldown", d.hintMonth||"", "mnz-drilldown-hint-monthly");'.
			'jQuery("#mnz-monthly-drilldown").off("click", ".mnz-drilldown-day-bar").on("click", ".mnz-drilldown-day-bar", function() {'.
				'var day = parseInt(jQuery(this).data("day"), 10); var monthIdx = (currentFilter.type==="day" ? currentFilter.monthIdx : currentFilter.value); var mk = monthKeys[monthIdx]; if (!mk || !day) return;'.
				'var dateStr = mk + "-" + (String(day).padStart(2, "0"));'.
				'var filtered = clocks.filter(function(t){ var dt=new Date(t*1000); var ds=dt.getFullYear()+"-"+(String(dt.getMonth()+1).padStart(2,"0"))+"-"+(String(dt.getDate()).padStart(2,"0")); return ds===dateStr; });'.
				'var parts = dateStr.split("-"); var y=parseInt(parts[0],10), m=parseInt(parts[1],10)-1, dNum=parseInt(parts[2],10); var sampleDate=new Date(y,m,dNum); var weekday=sampleDate.getDay();'.
				'var hourly=[]; for(var h=0;h<24;h++) hourly[h]=0; for(var i=0;i<filtered.length;i++) hourly[new Date(filtered[i]*1000).getHours()]++;'.
				'var wh=[]; for(var w=0;w<7;w++){ wh[w]=[]; for(var h=0;h<24;h++) wh[w][h]=0; } for(var h=0;h<24;h++) wh[weekday][h]=hourly[h];'.
				'currentFilter={type:"day",monthIdx:monthIdx,day:day,weekday:weekday,dateStr:dateStr};'.
				'currentAgg={hourly:hourly,weekly:[],monthly:currentAgg.monthly,weeklyHourlyDetails:wh,monthlyDailyDetails:currentAgg.monthlyDailyDetails};'.
				'renderHeatmap(currentAgg); updateFilterBar(); jQuery("html, body").animate({scrollTop: jQuery("#mnz-investigation-heatmap").offset().top - 80}, 300);'.
			'});'.
		'});'.
		'updateFilterBar();'.
	'}'.
	'currentAgg = computeAggregates(clocks); applyAndRender();'.
	'if (d.hostid) loadSLAbyService(d.hostid);'.
	'function collectSLIValues(data) {'.
		'var values = [];'.
		'function fromService(s){ if (s && s.has_sla && s.sli != null) values.push(parseFloat(s.sli)); }'.
		'for (var t=0;t<data.service_trees.length;t++) {'.
			'var tree = data.service_trees[t];'.
			'if (tree.path_to_root) for (var p=0;p<tree.path_to_root.length;p++) fromService(tree.path_to_root[p]);'.
			'fromService(tree.impacted_service);'.
			'if (tree.children_tree) for (var c=0;c<tree.children_tree.length;c++) fromService(tree.children_tree[c]);'.
		'}'.
		'return values;'.
	'}'.
	'function collectSLO(data) {'.
		'var slos = [];'.
		'function fromService(s){ if (s && s.has_sla && s.slo != null) { var v=parseFloat(s.slo); if (!isNaN(v)) slos.push(v); } }'.
		'for (var t=0;t<data.service_trees.length;t++) {'.
			'var tree = data.service_trees[t];'.
			'if (tree.path_to_root) for (var p=0;p<tree.path_to_root.length;p++) fromService(tree.path_to_root[p]);'.
			'fromService(tree.impacted_service);'.
			'if (tree.children_tree) for (var c=0;c<tree.children_tree.length;c++) fromService(tree.children_tree[c]);'.
		'}'.
		'return slos.length > 0 ? Math.min.apply(Math, slos) : 99.9;'.
	'}'.
	'function renderSLIGauge(value, slo) {'.
		'var wrap = document.getElementById("mnz-sla-gauge-wrap"); if (!wrap) return;'.
		'var val = (value != null && !isNaN(value)) ? Math.min(100, Math.max(0, value)) : null;'.
		'var sloNum = (slo != null && !isNaN(slo)) ? Math.min(100, Math.max(1, parseFloat(slo))) : 99.9;'.
		'var isDark = document.body.getAttribute("theme")==="dark-theme" || document.documentElement.getAttribute("theme")==="dark-theme";'.
		'var txtCol = isDark ? "#e0e0e0" : "#333"; var bgCol = isDark ? "#2b2b2b" : "#fff";'.
		'var arcG = "#28a745"; var arcY = "#ffc107"; var arcR = "#dc3545";'.
		'var total = 151; var slo1 = Math.max(0, sloNum - 1);'.
		'var Lred = (slo1/100)*total; var Lyellow = ((sloNum-slo1)/100)*total; var Lgreen = ((100-sloNum)/100)*total;'.
		'var needleAngle = val != null ? -90 + (val/100)*180 : -90;'.
		'var displayVal = val != null ? val.toFixed(1) + "%" : "-";'.
		'var desc = "'. _('Current SLI') .'";'.
		'var bgArc = \'<path d="M 12 70 A 48 48 0 0 1 108 70" fill="none" stroke="\'+(isDark?"rgba(255,255,255,0.1)":"rgba(0,0,0,0.06)")+\'" stroke-width="10" stroke-linecap="round"/>\';'.
		'var redSeg = \'<path d="M 12 70 A 48 48 0 0 1 108 70" fill="none" stroke="\'+arcR+\'" stroke-width="10" stroke-linecap="round" stroke-dasharray="\'+Lred+\' \'+total+\'"/>\';'.
		'var yelSeg = \'<path d="M 12 70 A 48 48 0 0 1 108 70" fill="none" stroke="\'+arcY+\'" stroke-width="10" stroke-linecap="round" stroke-dasharray="\'+Lyellow+\' \'+total+\'" stroke-dashoffset="-\'+Lred+\'"/>\';'.
		'var grnSeg = \'<path d="M 12 70 A 48 48 0 0 1 108 70" fill="none" stroke="\'+arcG+\'" stroke-width="10" stroke-linecap="round" stroke-dasharray="\'+Lgreen+\' \'+total+\'" stroke-dashoffset="-\'+(Lred+Lyellow)+\'"/>\';'.
		'var needle = \'<g transform="rotate(\'+needleAngle+\' 60 70)"><line x1="60" y1="70" x2="60" y2="28" stroke="\'+txtCol+\'" stroke-width="2.5" stroke-linecap="round"/><circle cx="60" cy="70" r="5" fill="\'+txtCol+\'"/></g>\';'.
		'var valText = \'<text x="60" y="88" text-anchor="middle" font-size="16" font-weight="bold" fill="\'+txtCol+\'">\'+displayVal+\'</text>\';'.
		'wrap.innerHTML = \'<div class="mnz-sla-gauge" style="background:\'+bgCol+\'"><svg class="mnz-sla-gauge-svg" viewBox="0 0 120 100" preserveAspectRatio="xMidYMax meet">\'+bgArc+redSeg+yelSeg+grnSeg+needle+valText+\'</svg><div class="mnz-sla-gauge-label" style="color:\'+txtCol+\'">\'+desc+\' (SLO \'+sloNum.toFixed(1)+\'%)</div></div>\';'.
	'}'.
	'function loadSLAbyService(hostid) {'.
		'var el = document.getElementById("mnz-sla-service-container"); var gw = document.getElementById("mnz-sla-gauge-wrap"); if (!el) return;'.
		'el.innerHTML = \'<div class="mnz-sla-loading"><span>'. _('Loading services...') .'</span></div>\'; if (gw) gw.innerHTML = "";'.
		'var fd = new FormData(); fd.append("hostid", hostid);'.
		'fetch("zabbix.php?action=incident.serviceimpact", { method: "POST", body: fd })'.
			'.then(function(r){ return r.json(); })'.
			'.then(function(res){'.
				'if (!res.success) { el.innerHTML = \'<div class="mnz-sla-error">\'+(res.error&&res.error.message ? res.error.message : "Error")+\'</div>\'; if (gw) gw.innerHTML = ""; return; }'.
				'var data = res.data;'.
				'if (!data.service_trees || data.service_trees.length === 0) { el.innerHTML = \'<div class="mnz-sla-empty">'. _('No services affected by this incident') .'</div>\'; renderSLIGauge(null); return; }'.
				'var sliVals = collectSLIValues(data); var currentSLI = sliVals.length > 0 ? Math.min.apply(Math, sliVals) : null;'.
				'var slo = collectSLO(data); renderSLIGauge(currentSLI, slo); el.innerHTML = renderSLATreeCompact(data, slo);'.
			'})'.
			'.catch(function(){ el.innerHTML = \'<div class="mnz-sla-error">'. _('Failed to load service impact') .'</div>\'; if (gw) gw.innerHTML = ""; });'.
	'}'.
	'function renderSLATreeCompact(data, slo) {'.
		'var sloThresh = (slo != null && !isNaN(slo)) ? parseFloat(slo) : 99.9;'.
		'var html = \'<div class="mnz-sla-tree-compact">\';'.
		'html += \'<div class="mnz-sla-tree-title">\' + (data.summary.total_services || 0) + \' '. _('services') .'</div>\';'.
		'function addItem(service, level) {'.
			'var ind = (level || 0) * 12; var sliVal = (service.has_sla && service.sli != null) ? parseFloat(service.sli).toFixed(1) + "%" : "-";'.
			'var sliCl = (service.has_sla && service.sli != null) ? (parseFloat(service.sli) >= sloThresh ? "mnz-sli-ok" : "mnz-sli-bad") : "mnz-sli-na";'.
			'var name = String(service.name||"").replace(/</g,"&lt;");'.
			'html += \'<div class="mnz-sla-tree-item" style="padding-left:\'+ind+\'px"><span class="mnz-sla-tree-name" title="\'+name+\'">\'+name+\'</span><span class="mnz-sli-badge mnz-sli-badge-sm \'+sliCl+\'">\'+sliVal+\'</span></div>\';'.
			'if (service.children && service.children.length) for (var i=0;i<service.children.length;i++) addItem(service.children[i], (level||0)+1);'.
		'}'.
		'for (var t=0; t<data.service_trees.length; t++) {'.
			'var tree = data.service_trees[t];'.
			'if (tree.path_to_root && tree.path_to_root.length > 0) { for (var p=0;p<tree.path_to_root.length;p++) addItem(tree.path_to_root[p], p); }'.
			'else addItem(tree.impacted_service, 0);'.
			'if (tree.children_tree && tree.children_tree.length) { var bl = tree.path_to_root ? tree.path_to_root.length : 1; for (var c=0;c<tree.children_tree.length;c++) addItem(tree.children_tree[c], bl); }'.
		'}'.
		'html += \'</div>\'; return html;'.
	'}'.
'});';

$content->addItem(new CScriptTag($chart_script));

$toggle_script = 'jQuery(document).ready(function(){'.
	'jQuery(".mnz-section-toggle").on("click", function(e){ e.preventDefault(); var lnk=jQuery(this); var id=lnk.data("section"); var section=document.getElementById(id); if(!section) return; var collapsed=section.classList.toggle("mnz-section-collapsed"); lnk.text(collapsed ? "'. _('Expand') .'" : "'. _('Collapse') .'"); });'.
	'jQuery(".'.ZBX_STYLE_PAGING_BTN_CONTAINER.' button[data-view]").on("click", function(){'.
		'var btn=jQuery(this); var view=btn.data("view"); btn.siblings().removeClass("'.ZBX_STYLE_PAGING_SELECTED.'"); btn.addClass("'.ZBX_STYLE_PAGING_SELECTED.'");'.
		'jQuery("#mnz-timeline-view").toggle(view==="timeline"); jQuery("#mnz-actions-view").toggle(view==="actions");'.
	'});'.
'});';
$content->addItem(new CScriptTag($toggle_script));

$content->addItem(
	(new CDiv('Developed by MonZphere'))
		->addClass('mnz-module-footer')
);

$html_page->addItem($content);
$html_page->show();
