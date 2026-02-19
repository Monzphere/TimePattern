<?php declare(strict_types = 0);

namespace Modules\IncidentInvestigation\Actions;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;
use CWebUser;

class CControllerIncidentInvestigationView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'id',
			'triggerid' => 'id',
			'hostid' => 'id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		$eventid = $this->getInput('eventid', CProfile::get('mnz.incident.investigation.eventid', ''));
		$triggerid = (int) $this->getInput('triggerid', CProfile::get('mnz.incident.investigation.triggerid', 0));
		$hostid = (int) $this->getInput('hostid', CProfile::get('mnz.incident.investigation.hostid', 0));

		if ($this->hasInput('eventid')) {
			CProfile::update('mnz.incident.investigation.eventid', $eventid, PROFILE_TYPE_STR);
			CProfile::update('mnz.incident.investigation.triggerid', (string) $triggerid, PROFILE_TYPE_STR);
			CProfile::update('mnz.incident.investigation.hostid', (string) $hostid, PROFILE_TYPE_STR);
		}

		$data = [
			'eventid' => $eventid,
			'triggerid' => $triggerid,
			'hostid' => $hostid,
			'has_event' => false,
			'event' => [],
			'trigger' => null,
			'host' => null,
			'related_events' => [],
			'six_months_events' => [],
			'items' => [],
			'monthly_comparison' => [],
			'user' => ['debug_mode' => CWebUser::$data['debug_mode'] ?? 0]
		];

		if (empty($eventid)) {
			$response = new CControllerResponseData($data);
			$response->setTitle(_('Incident Investigation'));
			$this->setResponse($response);
			return;
		}

		$events = API::Event()->get([
			'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name', 'severity', 'r_eventid'],
			'eventids' => $eventid,
			'selectTags' => ['tag', 'value'],
			'selectAcknowledges' => ['clock', 'message', 'action', 'userid', 'old_severity', 'new_severity', 'suppress_until']
		]);
		$event = $events ? $events[0] : [];

		if (!$event) {
			$event = [
				'eventid' => $eventid,
				'name' => _('Event not found'),
				'severity' => 0,
				'clock' => time(),
				'acknowledged' => 0,
				'objectid' => 0,
				'value' => 1,
				'tags' => [],
				'r_eventid' => 0,
				'acknowledges' => []
			];
		}
		elseif (!isset($event['acknowledges'])) {
			$event['acknowledges'] = [];
		}
		if (!isset($event['r_eventid'])) {
			$event['r_eventid'] = 0;
		}

		$actual_triggerid = $triggerid ?: ($event['objectid'] ?? 0);
		$trigger = null;

		if ($actual_triggerid > 0) {
			$triggers = API::Trigger()->get([
				'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'manual_close'],
				'triggerids' => [$actual_triggerid],
				'selectHosts' => ['hostid', 'host', 'name'],
				'selectItems' => ['itemid', 'hostid', 'name', 'key_'],
				'expandExpression' => true
			]);
			$trigger = $triggers ? $triggers[0] : null;
		}

		$actual_hostid = $hostid ?: ($trigger['hosts'][0]['hostid'] ?? 0);
		$host = null;

		if ($actual_hostid > 0) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name', 'status', 'description'],
				'hostids' => $actual_hostid,
				'selectHostGroups' => ['name'],
				'selectInterfaces' => ['type', 'main', 'ip', 'port'],
				'selectTags' => ['tag', 'value']
			]);
			$host = $hosts ? $hosts[0] : null;
		}

		$related_events = [];
		if ($actual_triggerid > 0) {
			$related_events = API::Event()->get([
				'output' => ['eventid', 'clock', 'value', 'acknowledged', 'name', 'severity'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'sortfield' => 'clock',
				'sortorder' => 'DESC',
				'limit' => 20
			]);
			$trigger_severity = $trigger['priority'] ?? 0;
			$last_sev = 0;
			$related_events = array_reverse($related_events);
			foreach ($related_events as &$ev) {
				if ($ev['value'] == 1) {
					$last_sev = (int) $ev['severity'];
				} else {
					$ev['severity'] = $last_sev ?: $trigger_severity;
				}
			}
			unset($ev);
			$related_events = array_reverse($related_events);
		}

		$six_months_events = [];
		if ($actual_triggerid > 0) {
			$all_events = API::Event()->get([
				'output' => ['eventid', 'clock', 'value', 'severity', 'name'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => strtotime('-12 months'),
				'sortfield' => ['clock', 'eventid'],
				'sortorder' => 'ASC'
			]);
			$pending = [];
			foreach ($all_events as $evt) {
				if ($evt['value'] == 1) {
					$pending[] = ['eventid' => $evt['eventid'], 'clock' => $evt['clock'], 'r_clock' => 0,
						'severity' => $evt['severity'], 'name' => $evt['name'], 'value' => 1];
				} else {
					if (!empty($pending)) {
						$p = array_shift($pending);
						$p['r_clock'] = $evt['clock'];
						$six_months_events[] = $p;
					}
				}
			}
			foreach ($pending as $p) {
				$six_months_events[] = $p;
			}
			usort($six_months_events, function($a, $b) { return $b['clock'] - $a['clock']; });
		}

		$items = [];
		if ($trigger && !empty($trigger['items'])) {
			$itemids = array_unique(array_column($trigger['items'], 'itemid'));
			$raw = API::Item()->get([
				'output' => ['itemid', 'name', 'key_', 'hostid', 'value_type'],
				'itemids' => $itemids,
				'monitored' => true,
				'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]]
			]);
			$items = array_values($raw);
		}

		$monthly_comparison = [];
		if ($actual_triggerid > 0 && isset($event['clock'])) {
			$ts = $event['clock'];
			$cur_start = mktime(0, 0, 0, (int)date('n', $ts), 1, (int)date('Y', $ts));
			$cur_end = mktime(23, 59, 59, (int)date('n', $ts), (int)date('t', $ts), (int)date('Y', $ts));
			$prev_start = mktime(0, 0, 0, (int)date('n', $ts) - 1, 1, (int)date('Y', $ts));
			$prev_end = mktime(23, 59, 59, (int)date('n', $ts) - 1, (int)date('t', $prev_start), (int)date('Y', $ts));
			if (date('n', $ts) == 1) {
				$prev_start = mktime(0, 0, 0, 12, 1, (int)date('Y', $ts) - 1);
				$prev_end = mktime(23, 59, 59, 12, 31, (int)date('Y', $ts) - 1);
			}
			$cur_ev = API::Event()->get([
				'output' => ['eventid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => $cur_start,
				'time_till' => $cur_end,
				'value' => 1
			]);
			$prev_ev = API::Event()->get([
				'output' => ['eventid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => [$actual_triggerid],
				'time_from' => $prev_start,
				'time_till' => $prev_end,
				'value' => 1
			]);
			$cur_cnt = count($cur_ev);
			$prev_cnt = count($prev_ev);
			$monthly_comparison = [
				'current_month' => ['name' => $this->formatMonth($ts), 'count' => $cur_cnt],
				'previous_month' => ['name' => $this->formatMonth($prev_start), 'count' => $prev_cnt],
				'change_percentage' => $prev_cnt > 0 ? round((($cur_cnt - $prev_cnt) / $prev_cnt) * 100, 1) : ($cur_cnt > 0 ? 100 : 0)
			];
		}

		$actions_data = ['result' => null, 'users' => [], 'mediatypes' => []];
		if ($event && !empty($event['eventid']) && isset($event['clock'])) {
			require_once dirname(__FILE__).'/../../../include/actions.inc.php';
			$actions_raw = getEventDetailsActions($event);
			$actions_data['result'] = $actions_raw;
			if (!empty($actions_raw['userids'])) {
				$actions_data['users'] = API::User()->get([
					'output' => ['username', 'name', 'surname'],
					'userids' => array_keys($actions_raw['userids']),
					'preservekeys' => true
				]);
			}
			if (!empty($actions_raw['mediatypeids'])) {
				$actions_data['mediatypes'] = API::Mediatype()->get([
					'output' => ['maxattempts'],
					'mediatypeids' => array_keys($actions_raw['mediatypeids']),
					'preservekeys' => true
				]);
			}
		}

		$data['actions_data'] = $actions_data;
		$data['has_event'] = true;
		$data['event'] = $event;
		$data['trigger'] = $trigger;
		$data['host'] = $host;
		$data['related_events'] = $related_events;
		$data['six_months_events'] = $six_months_events;
		$data['items'] = $items;
		$data['monthly_comparison'] = $monthly_comparison;

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Incident Investigation'));
		$this->setResponse($response);
	}

	private function formatMonth(int $ts): string {
		$months = [
			1 => _('January'), 2 => _('February'), 3 => _('March'), 4 => _('April'),
			5 => _('May'), 6 => _('June'), 7 => _('July'), 8 => _('August'),
			9 => _('September'), 10 => _('October'), 11 => _('November'), 12 => _('December')
		];
		return ($months[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
	}
}
