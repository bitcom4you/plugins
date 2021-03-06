<?php
/**
 * i-MSCP CronJobs plugin
 * Copyright (C) 2014 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace CronJobs\Admin;

use CronJobs\CommonFunctions as Functions;
use CronJobs\Exception\CronjobException;
use CronJobs\Utils\CronjobValidator as CronjobValidator;
use iMSCP_Database as Database;
use iMSCP_Events as Events;
use iMSCP_Events_Aggregator as EventsAggregator;
use iMSCP_Exception_Database as DatabaseException;
use iMSCP_Plugin_Manager as PluginManager;
use iMSCP_pTemplate as TemplateEngine;
use iMSCP_Registry as Registry;
use PDO;

/***********************************************************************************************************************
 * Functions
 */

/**
 * Get cron job permissions
 *
 * @return void
 */
function getCronPermissions()
{
	if(isset($_GET['cron_permission_id'])) {
		$cronPermissionId = intval($_GET['cron_permission_id']);

		try {
			$stmt = exec_query(
				'
					SELECT
						cron_permission_id, cron_permission_admin_id, cron_permission_type, cron_permission_frequency,
						admin_name
					FROM
						cron_permissions
					INNER JOIN
						admin ON(admin_id = cron_permission_admin_id)
					WHERE
						cron_permission_id = ?
				',
				intval($cronPermissionId)
			);

			if($stmt->rowCount()) {
				Functions::sendJsonResponse(200, $stmt->fetchRow(PDO::FETCH_ASSOC));
			}
		} catch(DatabaseException $e) {
			write_log(sprintf('CronJobs: Unable to get cron job permissions: %s', $e->getMessage()), E_USER_ERROR);
			Functions::sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
}

/**
 * Add/Update cron job permissions
 *
 * @return void
 */
function addCronPermissions()
{
	if(
		isset($_POST['cron_permission_id']) && isset($_POST['cron_permission_admin_id']) &&
		isset($_POST['admin_name']) && isset($_POST['cron_permission_type']) &&
		isset($_POST['cron_permission_frequency'])
	) {
		$cronPermissionId = intval($_POST['cron_permission_id']);
		$cronPermissionAdminId = intval($_POST['cron_permission_admin_id']);
		$adminName = clean_input($_POST['admin_name']);
		$cronPermissionType = clean_input($_POST['cron_permission_type']);
		$cronPermissionFrequency = clean_input($_POST['cron_permission_frequency']);

		if(in_array($cronPermissionType, array('url', 'jailed', 'full'), true) && $adminName !== '') {
			if($cronPermissionFrequency === '' || !is_number($cronPermissionFrequency)) {
				Functions::sendJsonResponse(
					400,
					array(
						'message' => tr("Wrong value for the 'Cron jobs frequency' field. Please, enter a number.", true)
					)
				);
			} elseif($cronPermissionFrequency == 0) {
				$cronPermissionFrequency = 1;
			}

			$db = Database::getInstance();

			try {
				if(!$cronPermissionId) { // Add cron job permissions
					EventsAggregator::getInstance()->dispatch('onBeforeAddCronjobPermissions', array(
						'admin_name' => $adminName,
						'cron_permission_type' => $cronPermissionType,
						'cron_permission_max' => 0,
						'cron_permission_frequency' => $cronPermissionFrequency
					));

					$stmt = exec_query(
						'
							INSERT INTO cron_permissions(
								cron_permission_admin_id, cron_permission_type, cron_permission_frequency,
								cron_permission_status
							) SELECT
								admin_id, ?, ?, ?
							FROM
								admin
							WHERE
								admin_name = ?
						',
						array($cronPermissionType, $cronPermissionFrequency, 'ok', $adminName)
					);

					EventsAggregator::getInstance()->dispatch('onAfterAddCronjobPermissions', array(
						'admin_name' => $adminName,
						'cron_permission_id' => $db->insertId(),
						'cron_permission_type' => $cronPermissionType,
						'cron_permission_max' => 0,
						'cron_permission_frequency' => $cronPermissionFrequency,
					));

					if($stmt->rowCount()) {
						write_log(sprintf('CronJobs: Cron job permissions were added for %s', $adminName), E_USER_NOTICE);
						Functions::sendJsonResponse(200, array('message' => tr('Cron job permissions were added.', true)));
					}
				} else { // Update cron job permissions
					$db->beginTransaction();

					# We must ensure that no child item is currently processed to avoid any race condition
					$stmt = exec_query(
						'
							SELECT
								COUNT(cron_job_id) AS nb_cron_jobs
							FROM
								cron_jobs
							INNER JOIN
								admin ON(admin_id = cron_job_admin_id)
							WHERE
								created_by = ?
							AND
								cron_job_status <> ?
						',
						array($cronPermissionAdminId, 'ok')
					);

					$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

					if($row['nb_cron_jobs'] == 0) {
						EventsAggregator::getInstance()->dispatch('onBeforeUpdateCronjobPermissions', array(
							'admin_name' => $adminName,
							'cron_permission_id' => $cronPermissionId,
							'cron_permission_type' => $cronPermissionType,
							'cron_permission_max' => 0,
							'cron_permission_frequency' => $cronPermissionFrequency,
						));

						$stmt = exec_query(
							'
								UPDATE
									cron_permissions
								SET
									cron_permission_type = ?, cron_permission_frequency = ?, cron_permission_status = ?
								WHERE
									cron_permission_id = ?
							',
							array($cronPermissionType, $cronPermissionFrequency, 'ok', $cronPermissionId)
						);

						if($stmt->rowCount()) {
							# Update any cron job permissions ( in the context of the reseller )
							exec_query(
								"
									UPDATE
										cron_permissions
									INNER JOIN
										admin ON(admin_id = cron_permission_admin_id)
									SET
										cron_permission_type = IF(
											cron_permission_type != 'url' && :cron_permission_type = 'jailed',
											:cron_permission_type,
											cron_permission_type
										),
										cron_permission_frequency = IF(
											cron_permission_frequency < :cron_permission_frequency,
											:cron_permission_frequency,
											cron_permission_frequency
										)
									WHERE
										created_by = :reseller_id
								",
								array(
									'cron_permission_type' => $cronPermissionType,
									'cron_permission_frequency' => $cronPermissionFrequency,
									'reseller_id' => $cronPermissionAdminId
								)
							);

							$sendRequest = false;

							$stmt = exec_query(
								"
									SELECT
										cron_job_id, cron_job_type, cron_job_notification, cron_job_minute,
										cron_job_hour, cron_job_dmonth, cron_job_month, cron_job_dweek, cron_job_command
									FROM
										cron_jobs
									INNER JOIN
										admin ON(admin_id = cron_job_admin_id)
									WHERE
										created_by = ?
								",
								$cronPermissionAdminId
							);

							if($stmt->rowCount()) {
								// Schedule deletion of any cron jobs which doesn't fit with the new cron jobs frequency
								while($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
									try {
										CronjobValidator::validate(
											$row['cron_job_notification'], $row['cron_job_minute'], $row['cron_job_hour'],
											$row['cron_job_dmonth'], $row['cron_job_month'], $row['cron_job_dweek'],
											null, $row['cron_job_command'], $row['cron_job_type'], $cronPermissionFrequency
										);
									} catch(CronjobException $e) {
										exec_query(
											'UPDATE cron_jobs SET cron_job_status = ? WHERE cron_job_id = ?',
											array('todelete', $row['cron_job_id'])
										);

										$sendRequest = true;
									}
								}

								# Schedule change of any cron jobs ( in the context of the reseller )
								$stmt = exec_query(
									"
										UPDATE
											cron_jobs
										INNER JOIN
											admin ON(admin_id = cron_job_admin_id)
										SET
											cron_job_type = IF(
												cron_job_type != 'url' && :cron_job_type = 'jailed',
												:cron_job_type,
												cron_job_type
											),
											cron_job_status = :new_cron_job_status
										WHERE
											cron_job_status != :cron_job_status
										AND
											created_by = :reseller_id
									",
									array(
										'cron_job_type' => $cronPermissionType,
										'new_cron_job_status' => 'tochange',
										'cron_job_status' => 'todelete',
										'reseller_id' => $cronPermissionAdminId
									)
								);

								if($stmt->rowCount()) {
									$sendRequest = true;
								}
							}

							$db->commit();

							EventsAggregator::getInstance()->dispatch('onAfterUpdateCronjobPermissions', array(
								'admin_name' => $adminName,
								'cron_permission_id' => $cronPermissionId,
								'cron_permission_type' => $cronPermissionType,
								'cron_permission_max' => 0,
								'cron_permission_frequency' => $cronPermissionFrequency
							));

							if($sendRequest) {
								send_request();
							}

							write_log(sprintf('Cron job permissions were updated for %s', $adminName), E_USER_NOTICE);
							Functions::sendJsonResponse(
								200, array('message' => tr('Cron job permissions were updated.', true))
							);
						}

						Functions::sendJsonResponse(202, array('message' => tr('Nothing has been changed.', true)));
					} else {
						Functions::sendJsonResponse(
							409,
							array(
								'message' => tr(
									"One or many cron jobs which belongs to the reseller's customers are currently processed. Please retry in few minutes.",
									true
								)
							)
						);
					}
				}
			} catch(DatabaseException $e) {
				$db->rollBack();

				if($e->getCode() != '23000') {
					write_log(
						sprintf('CronJobs: Unable to update cron job permissions for %s: %s', $adminName, $e->getMessage()),
						E_USER_ERROR
					);
					Functions::sendJsonResponse(
						500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
					);
				}
			}
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
}

/**
 * Delete cron job permissions
 *
 * @return void
 */
function deleteCronPermissions()
{
	if(isset($_POST['cron_permission_id']) && isset($_POST['cron_permission_admin_id'])) {
		$cronPermissionId = intval($_POST['cron_permission_id']);
		$cronPermissionAdminId = intval($_POST['cron_permission_admin_id']);

		$db = Database::getInstance();

		try {
			EventsAggregator::getInstance()->dispatch('onBeforeDeleteCronPermissions', array(
				'cron_permission_id' => $cronPermissionId,
				'cron_permission_admin_id' => $cronPermissionAdminId
			));

			$sendRequest = false;
			$db->beginTransaction();

			$stmt = exec_query('DELETE FROM cron_permissions WHERE cron_permission_id = ?', $cronPermissionId);

			if($stmt->rowCount()) {
				$stmt = exec_query(
					'
						UPDATE
							cron_permissions
						SET
							cron_permission_status = ?
						WHERE
							cron_permission_admin_id IN(SELECT admin_id FROM admin WHERE created_by = ?)
					',
					array('todelete', $cronPermissionAdminId)
				);

				if($stmt->rowCount()) {
					exec_query(
						'
							UPDATE
								cron_jobs
							SET
								cron_job_status = ?
							WHERE
								cron_job_admin_id IN(SELECT admin_id FROM admin WHERE created_by = ?)
						',
						array('todelete', $cronPermissionAdminId)
					);

					$sendRequest = true;
				}

				$db->commit();

				EventsAggregator::getInstance()->dispatch('onAfterDeleteCronPermissions', array(
					'cron_permission_id' => $cronPermissionId,
					'cron_permission_admin_id' => $cronPermissionAdminId
				));

				if($sendRequest) {
					send_request();
				}

				write_log(
					sprintf('CronJobs: Cron job permissions with ID %s were revoked', $cronPermissionId), E_USER_NOTICE
				);
				Functions::sendJsonResponse(200, array('message' => tr('Cron job permissions were revoked.', true)));
			}
		} catch(DatabaseException $e) {
			$db->rollBack();
			write_log(
				sprintf(
					'CronJobs: Unable to revoke cron job permissions with ID %s: %s', $cronPermissionId, $e->getMessage()
				),
				E_USER_ERROR
			);
			Functions::sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
}

/**
 * Search reseller
 *
 * @return void
 */
function searchReseller()
{
	if(isset($_GET['term'])) {
		$term = clean_input($_GET['term']) . '%';

		try {
			$stmt = exec_query(
				'
					SELECT
						admin_name
					FROM
						admin
					WHERE
						admin_name LIKE ?
					AND
						admin_type = ?
					AND
						admin_id NOT IN(SELECT cron_permission_admin_id FROM cron_permissions)
				',
				array($term, 'reseller')
			);

			if($stmt->rowCount()) {
				$responseData = array();
				while($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
					$responseData[] = $row['admin_name'];
				}
			} else {
				$responseData = array();
			}

			Functions::sendJsonResponse(200, $responseData);
		} catch(DatabaseException $e) {
			write_log(sprintf('CronJobs: Unable to search reseller: %s', $e->getMessage()), E_USER_ERROR);

			Functions::sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
}

/**
 * Get cron job permissions list
 *
 * @return void
 */
function getCronPermissionsList()
{
	try {
		// Filterable, orderable columns
		$columns = array('admin_name', 'cron_permission_type', 'cron_permission_frequency', 'cron_permission_status');

		$nbColumns = count($columns);
		$indexColumn = 'cron_permission_id';

		/* DB table to use */
		$table = 'cron_permissions';

		/* Paging */
		$limit = '';

		if(isset($_GET['iDisplayStart']) && isset($_GET['iDisplayLength']) && $_GET['iDisplayLength'] !== '-1') {
			$limit = 'LIMIT ' . intval($_GET['iDisplayStart']) . ', ' . intval($_GET['iDisplayLength']);
		}

		/* Ordering */
		$order = '';

		if(isset($_GET['iSortCol_0']) && isset($_GET['iSortingCols'])) {
			$order = 'ORDER BY ';

			for($i = 0; $i < intval($_GET['iSortingCols']); $i++) {
				if($_GET['bSortable_' . intval($_GET['iSortCol_' . $i])] == 'true') {
					$sortDir = (
						isset($_GET['sSortDir_' . $i]) && in_array($_GET['sSortDir_' . $i], array('asc', 'desc'))
					) ? $_GET['sSortDir_' . $i] : 'asc';

					$order .= $columns[intval($_GET['iSortCol_' . $i])] . ' ' . $sortDir . ', ';
				}
			}

			$order = substr_replace($order, '', -2);

			if($order == 'ORDER BY ') {
				$order = '';
			}
		}

		/* Filtering */
		$where = "WHERE admin_type = 'reseller'";

		if($_GET['sSearch'] !== '') {
			$where .= 'AND (';

			for($i = 0; $i < $nbColumns; $i++) {
				$where .= $columns[$i] . ' LIKE ' . quoteValue('%' . $_GET['sSearch'] . '%') . ' OR ';
			}

			$where = substr_replace($where, '', -3);
			$where .= ')';
		}

		/* Individual column filtering */
		for($i = 0; $i < $nbColumns; $i++) {
			if(isset($_GET['bSearchable_' . $i]) && $_GET['bSearchable_' . $i] === 'true' && $_GET['sSearch_' . $i] !== '') {
				$where .= "AND {$columns[$i]} LIKE " . quoteValue('%' . $_GET['sSearch_' . $i] . '%');
			}
		}

		/* Get data to display */
		$rResult = execute_query(
			'
				SELECT
					SQL_CALC_FOUND_ROWS ' . str_replace(' , ', ' ', implode(', ', $columns)) . ",
					cron_permission_id, cron_permission_admin_id
				FROM
					$table
				INNER JOIN
					admin ON(admin_id = cron_permission_admin_id)
				$where
				$order
				$limit
			"
		);

		/* Data set length after filtering */
		$resultFilterTotal = execute_query('SELECT FOUND_ROWS()');
		$resultFilterTotal = $resultFilterTotal->fetchRow(PDO::FETCH_NUM);
		$filteredTotal = $resultFilterTotal[0];

		/* Total data set length */
		$resultTotal = execute_query(
			"
				SELECT
					COUNT($indexColumn)
				FROM
					$table
				INNER JOIN
					admin ON(admin_id = cron_permission_admin_id)
				WHERE
					admin_type = 'reseller'
			"
		);
		$resultTotal = $resultTotal->fetchRow(PDO::FETCH_NUM);
		$total = $resultTotal[0];

		/* Output */
		$output = array(
			'sEcho' => intval($_GET['sEcho']),
			'iTotalRecords' => $total,
			'iTotalDisplayRecords' => $filteredTotal,
			'aaData' => array()
		);

		$trEditTooltip = tr('Edit permissions', true);
		$trDeleteTooltip = tr('Revoke permissions', true);

		while($data = $rResult->fetchRow(PDO::FETCH_ASSOC)) {
			$row = array();

			for($i = 0; $i < $nbColumns; $i++) {
				if($columns[$i] == 'cron_permission_type') {
					$row[$columns[$i]] = tr(ucfirst($data[$columns[$i]]), true);
				} elseif($columns[$i] == 'cron_permission_frequency') {
					$row[$columns[$i]] = tr(
						array('%d minute', '%d minutes', $data[$columns[$i]]), true, $data[$columns[$i]]
					);
				} elseif($columns[$i] == 'cron_permission_status') {
					$row[$columns[$i]] = translate_dmn_status($data[$columns[$i]], false);
				} else {
					$row[$columns[$i]] = $data[$columns[$i]];
				}
			}

			if($data['cron_permission_status'] == 'ok') {
				$row['cron_permission_actions'] =
					"<span title=\"$trEditTooltip\" data-action=\"edit_cron_permissions\" " .
					"data-cron-permission-id=\"" . $data['cron_permission_id'] . "\" " .
					"class=\"icon icon_edit clickable\">&nbsp;</span> "
					.
					"<span title=\"$trDeleteTooltip\" data-action=\"delete_cron_permissions\" " .
					"data-cron-permission-id=\"" . $data['cron_permission_id'] . "\" " .
					"data-cron-permission-admin-id=\"" . $data['cron_permission_admin_id'] . "\" " .
					"class=\"icon icon_delete clickable\">&nbsp;</span>";
			} else {
				$row['cron_permission_actions'] = tr('n/a', true);
			}

			$output['aaData'][] = $row;
		}

		Functions::sendJsonResponse(200, $output);
	} catch(DatabaseException $e) {
		write_log(sprintf('CronJobs: Unable to get cron job permissions list: %s', $e->getMessage()), E_USER_ERROR);

		Functions::sendJsonResponse(
			500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
		);
	}

	Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
}

/***********************************************************************************************************************
 * Main
 */

EventsAggregator::getInstance()->dispatch(Events::onAdminScriptStart);

check_login('admin');

if(isset($_REQUEST['action'])) {
	if(is_xhr()) {
		$action = clean_input($_REQUEST['action']);

		switch($action) {
			case 'get_cron_permissions_list':
				getCronPermissionsList();
				break;
			case 'search_reseller':
				searchReseller();
				break;
			case 'add_cron_permissions':
				addCronPermissions();
				break;
			case 'get_cron_permissions':
				getCronPermissions();
				break;
			case 'delete_cron_permissions':
				deleteCronPermissions();
				break;
			default:
				Functions::sendJsonResponse(400, array('message' => tr('Bad request.', true)));
		}
	}

	showBadRequestErrorPage();
}

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
	'layout' => 'shared/layouts/ui.tpl',
	'page_message' => 'layout'
));

$tpl->define_no_file_dynamic(array(
	'page' => Functions::renderTpl(PLUGINS_PATH . '/CronJobs/themes/default/view/admin/cronjobs_permissions.tpl'),
	'cron_permission_jailed' => 'page'
));

if(Registry::get('config')->DEBUG) {
	$assetVersion = time();
} else {
	$pluginInfo = Registry::get('pluginManager')->getPluginInfo('CronJobs');
	$assetVersion = strtotime($pluginInfo['date']);
}

$tpl->assign(array(
	'TR_PAGE_TITLE' => Functions::escapeHtml(tr('Admin / Settings / Cron Job Permissions', true)),
	'ISP_LOGO' => layout_getUserLogo(),
	'CRONJOBS_ASSET_VERSION' => Functions::escapeUrl($assetVersion),
	'DATATABLE_TRANSLATIONS' => getDataTablesPluginTranslations()
));

/** @var PluginManager $pluginManager */
$pluginManager = Registry::get('pluginManager');

if($pluginManager->isPluginKnown('InstantSSH')) {
	$info = $pluginManager->getPluginInfo('InstantSSH');

	if(version_compare($info['version'], '3.1.0', '<')) {
		$tpl->assign('CRON_PERMISSION_JAILED', '');
	}
} else {
	$tpl->assign('CRON_PERMISSION_JAILED', '');
}

generateNavigation($tpl);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');

EventsAggregator::getInstance()->dispatch(Events::onAdminScriptEnd, array('templateEngine' => $tpl));

$tpl->prnt();
