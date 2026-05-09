<?php

/**
 * encounter_history_fragment.php
 *
 * Read-only encounter history summary for the patient dashboard.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI
 * @copyright Copyright (c) 2026 OpenAI
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\EncounterService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

$canViewEncounterHistory = (
    AclMain::aclCheckCore('encounters', 'notes_a') ||
    AclMain::aclCheckCore('encounters', 'notes') ||
    AclMain::aclCheckCore('encounters', 'coding_a') ||
    AclMain::aclCheckCore('encounters', 'coding') ||
    AclMain::aclCheckCore('encounters', 'relaxed')
);

$encounters = [];
$encounterHistoryMessage = '';

if (!$canViewEncounterHistory) {
    $encounterHistoryMessage = xlt('Encounter history is not available for this user role.');
} elseif (empty($pid)) {
    $encounterHistoryMessage = xlt('No prior encounters found for this patient.');
} else {
    $encounterService = new EncounterService();
    $processingResult = $encounterService->search(['pid' => $pid], true, '', [
        'limit' => 7,
        'order' => 'fe.`date` DESC',
    ]);

    if ($processingResult && !$processingResult->hasErrors() && $processingResult->hasData()) {
        $encounters = $processingResult->getData();
    }

    if (empty($encounters)) {
        $encounterHistoryMessage = xlt('No prior encounters found for this patient.');
    }
}
?>
<div id="encounter-history-fragment" class="px-2 pb-2">
    <?php if (!empty($encounterHistoryMessage)) { ?>
        <div class="text-muted py-2">
            <?php echo text($encounterHistoryMessage); ?>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-2">
                <thead class="thead-light">
                <tr>
                    <th scope="col"><?php echo xlt('Date'); ?></th>
                    <th scope="col"><?php echo xlt('Type'); ?></th>
                    <th scope="col"><?php echo xlt('Reason / Summary'); ?></th>
                    <th scope="col"><?php echo xlt('Provider'); ?></th>
                    <th scope="col"><?php echo xlt('Status'); ?></th>
                    <th scope="col" class="text-right"><?php echo xlt('View'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($encounters as $encounter) {
                    $encounterDate = !empty($encounter['date']) ? oeFormatShortDate(date('Y-m-d', strtotime((string) $encounter['date']))) : xlt('Unknown');
                    $encounterType = $encounter['pc_catname'] ?? $encounter['class_title'] ?? xlt('Encounter');
                    $encounterReason = trim((string) ($encounter['reason'] ?? ''));
                    if ($encounterReason === '') {
                        $encounterReason = $encounterType;
                    }

                    $providerDisplay = '';
                    if (!empty($encounter['provider_id'])) {
                        $providerDisplay = trim((string) getProviderName($encounter['provider_id']));
                    }
                    if ($providerDisplay === '') {
                        $providerDisplay = $encounter['provider_username'] ?? xlt('Not recorded');
                    }

                    $statusDisplay = trim((string) ($encounter['discharge_disposition_text'] ?? ''));
                    if ($statusDisplay === '') {
                        $statusDisplay = xlt('Completed');
                    }

                    $viewHref = '';
                    if (!empty($encounter['eid'])) {
                        $viewHref = '../encounter/encounter_top.php?set_encounter=' . attr_url($encounter['eid']);
                    }
                    ?>
                    <tr>
                        <td><?php echo text($encounterDate); ?></td>
                        <td><?php echo text($encounterType); ?></td>
                        <td><?php echo text($encounterReason); ?></td>
                        <td><?php echo text($providerDisplay); ?></td>
                        <td><?php echo text($statusDisplay); ?></td>
                        <td class="text-right">
                            <?php if ($viewHref !== '') { ?>
                                <a href="<?php echo $viewHref; ?>" onclick="top.restoreSession()"><?php echo xlt('View'); ?></a>
                            <?php } else { ?>
                                <span class="text-muted"><?php echo xlt('View'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-0">
            <?php echo xlt('Read-only encounter history pulled from the existing OpenEMR encounter record.'); ?>
        </p>
    <?php } ?>
</div>
