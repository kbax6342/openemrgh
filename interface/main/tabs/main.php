<?php

/**
 * main.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @author    Kevin N. Baxter <kbaxter3434@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2024 Care Management Solutions, Inc. <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../globals.php');
require_once \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir() . '/ESign/Api.php';

use ESign\Api;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Menu\MainMenuRole;
use OpenEMR\Services\LogoService;
use OpenEMR\Services\ProductRegistrationService;
use OpenEMR\Services\VersionService;
use OpenEMR\Telemetry\TelemetryService;
use Symfony\Component\Filesystem\Path;

const ENV_DISABLE_TELEMETRY = 'OPENEMR_DISABLE_TELEMETRY';

$session = SessionWrapperFactory::getInstance()->getActiveSession();

$logoService = new LogoService();
$menuLogo = $logoService->getLogo('core/menu/primary/');
$versionService = new VersionService();
$softwareVersion = text((string) $versionService->getSoftwareVersion());
// Registration status and options.
$productRegistration = new ProductRegistrationService();
$product_row = $productRegistration->getProductDialogStatus();
$allowRegisterDialog = $product_row['allowRegisterDialog'] ?? false;
$allowTelemetry = $product_row['allowTelemetry'] ?? null; // for dialog
$allowEmail = $product_row['allowEmail'] ?? null; // for dialog

// Check if telemetry is disabled via environment variable
$disableTelemetry = OEEnvBag::getInstance()->getBoolean(ENV_DISABLE_TELEMETRY);
// Check if background service piggybacking is disabled via environment variable
$noBackgroundTasks = OEEnvBag::getInstance()->getBoolean('OPENEMR__NO_BACKGROUND_TASKS');
if ($disableTelemetry) {
    $allowRegisterDialog = false;
    $allowTelemetry = false;
}

// If running unit tests, then disable the registration dialog
if ($session->get('testing_mode', false)) {
    $allowRegisterDialog = false;
}
// If the user is not a super admin, then disable the registration dialog
if (!AclMain::aclCheckCore('admin', 'super')) {
    $allowRegisterDialog = false;
}

// Ensure token_main matches so this script can not be run by itself
//  If tokens do not match, then destroy the session and go back to log in screen
$token_main_php = $session->get('token_main_php');
if (
    $token_main_php === null ||
    (!array_key_exists('token_main', $_GET) || $_GET['token_main'] === '') ||
    $_GET['token_main'] !== $token_main_php
) {
// Below functions are from auth.inc, which is included in globals.php
    authCloseSession();
    authLoginScreen(false);
}
// this will not allow copy/paste of the link to this main.php page or a refresh of this main.php page
//  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
if (OEGlobalsBag::getInstance()->get('prevent_browser_refresh') > 1) {
    SessionUtil::unsetSession('token_main_php');
}

$esignApi = new Api();
$twig = (new TwigContainer(null, OEGlobalsBag::getInstance()->getKernel()))->getTwig();

?>
<!DOCTYPE html>
<html>

<head>
    <title><?php echo text($openemr_name); ?></title>

    <script>
        // This is to prevent users from losing data by refreshing or backing out of OpenEMR.
        //  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
        <?php if (OEGlobalsBag::getInstance()->get('prevent_browser_refresh') > 0) { ?>
        window.addEventListener('beforeunload', (event) => {
            if (!timed_out) {
                event.returnValue = <?php echo xlj('Recommend not leaving or refreshing or you may lose data.'); ?>;
            }
        });
        <?php } ?>

        <?php require(OEGlobalsBag::getInstance()->getSrcDir() . "/restoreSession.php"); ?>

        // Since this should be the parent window, this is to prevent calls to the
        // window that opened this window. For example when a new window is opened
        // from the Patient Flow Board or the Patient Finder.
        window.opener = null;
        window.name = "main";

        // This flag indicates if another window or frame is trying to reload the login
        // page to this top-level window.  It is set by javascript returned by auth.inc.php
        // and is checked by handlers of beforeunload events.
        var timed_out = false;
        // some globals to access using top.variable
        // note that 'let' or 'const' does not allow global scope here.
        // only use var
        var isPortalEnabled = "<?php echo OEGlobalsBag::getInstance()->getBoolean('portal_onsite_two_enable') ?>";
        // Set the csrf_token_js token that is used in the below js/tabs_view_model.js script
        var csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken($session)); ?>;
        // Separate CSRF token for calls to the REST/LocalApi stack (sent as the APICSRFTOKEN header).
        var api_csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken($session, 'api')); ?>;
        <?php
        $sessionSiteId = $session->get('site_id');
        $sessionSiteIdString = is_string($sessionSiteId) ? $sessionSiteId : '';
        ?>
        var site_id_js = <?php echo js_escape($sessionSiteIdString); ?>;
        var userDebug = <?php echo js_escape(OEGlobalsBag::getInstance()->get('user_debug')); ?>;
        var webroot_url = <?php echo js_escape($web_root); ?>;
        var jsLanguageDirection = <?php echo js_escape($session->get('language_direction')); ?> ||
        'ltr';
        var jsGlobals = {};
        // used in tabs_view_model.js.
        jsGlobals.enable_group_therapy = <?php echo js_escape((int) OEGlobalsBag::getInstance()->getBoolean('enable_group_therapy')); ?>;
        jsGlobals.languageDirection = jsLanguageDirection;
        jsGlobals.date_display_format = <?php echo js_escape(OEGlobalsBag::getInstance()->get('date_display_format')); ?>;
        jsGlobals.time_display_format = <?php echo js_escape(OEGlobalsBag::getInstance()->get('time_display_format')); ?>;
        jsGlobals.timezone = <?php echo js_escape(OEGlobalsBag::getInstance()->get('gbl_time_zone') ?? ''); ?>;
        jsGlobals.assetVersion = <?php echo js_escape(OEGlobalsBag::getInstance()->get('v_js_includes')); ?>;
        var WindowTitleAddPatient = <?php echo(OEGlobalsBag::getInstance()->getBoolean('window_title_add_patient_name') ? 'true' : 'false'); ?>;
        var WindowTitleBase = <?php echo js_escape($openemr_name); ?>;
        const isSms = "<?php echo !empty(OEGlobalsBag::getInstance()->get('oefax_enable_sms') ?? null); ?>";
        const isFax = "<?php echo !empty(OEGlobalsBag::getInstance()->get('oefax_enable_fax')) ?? null?>";
        const isServicesOther = (isSms || isFax);
        var telemetryEnabled = <?php echo js_escape((new TelemetryService())->isTelemetryEnabled()); ?>;
        var noBackgroundTasks = <?php echo $noBackgroundTasks ? 'true' : 'false'; ?>;

        /**
         * Async function to get session value from the server
         * Usage Example
         * let authUser;
         * let sessionPid = await top.getSessionValue('pid');
         * // If using then() method a promise is returned instead of the value.
         * await top.getSessionValue('authUser').then(function (auth) {
         *    authUser = auth;
         *    console.log('authUser', authUser);
         * });
         * console.log('session pid', sessionPid);
         * console.log('auth User', authUser);
         */
        async function getSessionValue(key) {
            restoreSession();
            let csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken($session)); ?>;
            const config = {
                url: `${webroot_url}/library/ajax/set_pt.php?csrf_token_form=${csrf_token_js}`,
                method: 'POST',
                data: {
                    mode: 'session_key',
                    key: key
                }
            };
            try {
                const response = await $.ajax(config);
                restoreSession();
                return response;
            } catch (error) {
                throw error;
            }
        }

        function goRepeaterServices() {
            // Ensure send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR.

            // Send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR. Notify App for various portal and reminder alerts.
            // Combined portal and reminders ajax to fetch sjp 06-07-2020.
            // Incorporated timeout mechanism in 2021
            restoreSession();
            let request = new FormData;
            request.append("skip_timeout_reset", "1");
            request.append("isPortal", isPortalEnabled);
            request.append("isServicesOther", isServicesOther);
            request.append("isSms", isSms);
            request.append("isFax", isFax);
            request.append("csrf_token_form", csrf_token_js);
            fetch(webroot_url + "/library/ajax/dated_reminders_counter.php", {
                method: 'POST',
                credentials: 'same-origin',
                body: request
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('Reminders start failed. Status Code: ' + response.status);
                    return;
                }
                return response.json();
            }).then((data) => {
                if (data.timeoutMessage && (data.timeoutMessage == 'timeout')) {
                    // timeout has happened, so logout
                    timeoutLogout();
                }
                if (isPortalEnabled) {
                    let mail = data.mailCnt;
                    let chats = data.chatCnt;
                    let audits = data.auditCnt;
                    let payments = data.paymentCnt;
                    let total = data.total;
                    let enable = ((1 * mail) + (1 * audits)); // payments are among audits.
                    // Send portal counts to notification button model
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().portal(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().portalAlerts(total);
                        app_view_model.application_data.user().portalAudits(audits);
                        app_view_model.application_data.user().portalMail(mail);
                        app_view_model.application_data.user().portalChats(chats);
                        app_view_model.application_data.user().portalPayments(payments);
                    }
                }
                if (isServicesOther) {
                    let sms = data.smsCnt;
                    let fax = data.faxCnt;
                    let total = data.serviceTotal;
                    let enable = ((1 * sms) + (1 * fax));
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().servicesOther(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().serviceAlerts(total);
                        app_view_model.application_data.user().smsAlerts(sms);
                        app_view_model.application_data.user().faxAlerts(fax);
                    }
                }
                // Always send reminder count text to model
                app_view_model.application_data.user().messages(data.reminderText);
            }).catch(function (error) {
                console.log('Request failed', error);
            });

            // run background-services
            // delay 10 seconds to prevent both utility trigger at close to same time.
            // Both call globals so that is my concern.
            if (!noBackgroundTasks) {
                setTimeout(function () {
                    restoreSession();
                    // Call the REST "run all due" endpoint via LocalApi (APICSRFTOKEN header).
                    // The REST stack does not touch SessionTracker, so no skip_timeout_reset
                    // equivalent is needed to avoid resetting the session expiration timer.
                    fetch(webroot_url + "/apis/" + site_id_js + "/api/background_service/$run", {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'APICSRFTOKEN': api_csrf_token_js
                        }
                    }).then((response) => {
                        if (response.status !== 200) {
                            console.log('Background Service start failed. Status Code: ' + response.status);
                        }
                    }).catch(function (error) {
                        console.log('HTML Background Service start Request failed: ', error);
                    });
                }, 10000);
            }

            // auto run this function every 60 seconds
            var repeater = setTimeout("goRepeaterServices()", 60000);
        }

        function isEncounterLocked(encounterId) {
            <?php if ($esignApi->lockEncounters()) { ?>
            // If encounter locking is enabled, make a synchronous call (async=false) to check the
            // DB to see if the encounter is locked.
            // Call restore session, just in case
            // @TODO next clean up pass, turn into await promise then modify tabs_view_model.js L-309
            restoreSession();
            let url = webroot_url + "/interface/esign/index.php?module=encounter&method=esign_is_encounter_locked";
            $.ajax({
                type: 'POST',
                url: url,
                data: {
                    encounterId: encounterId
                },
                success: function (data) {
                    encounter_locked = data;
                },
                dataType: 'json',
                async: false
            });
            return encounter_locked;
            <?php } else { ?>
            // If encounter locking isn't enabled then always return false
            return false;
            <?php } ?>
        }
    </script>

    <?php Header::setupHeader(['knockout', 'tabs-theme', 'i18next', 'hotkeys', 'i18formatting']); ?>
    <link rel="stylesheet" href="<?php echo attr_url(OEGlobalsBag::getInstance()->getWebRoot()); ?>/interface/ai_copilot/copilot_widget.css?v=<?php echo attr_url((string) ($v_js_includes ?? time())); ?>">
    <script>
        // set up global translations for js
        function setupI18n(lang_id) {
            restoreSession();
            return fetch(<?php echo js_escape(OEGlobalsBag::getInstance()->getWebRoot()) ?> +"/library/ajax/i18n_generator.php?lang_id=" + encodeURIComponent(lang_id) + "&csrf_token_form=" + encodeURIComponent(csrf_token_js), {
                credentials: 'same-origin',
                method: 'GET'
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('I18n setup failed. Status Code: ' + response.status);
                    return [];
                }
                return response.json();
            })
        }

        setupI18n(<?php echo js_escape($session->get('language_choice')); ?>).then(translationsJson => {
            i18next.init({
                lng: 'selected',
                debug: false,
                nsSeparator: false,
                keySeparator: false,
                resources: {
                    selected: {
                        translation: translationsJson
                    }
                }
            });
        }).catch(error => {
            console.log(error.message);
        });

        /**
         * Assign and persist documents to portal patients
         * @var int patientId pid
         */
        function assignPatientDocuments(patientId) {
            let url = top.webroot_url + '/portal/import_template_ui.php?from_demo_pid=' + encodeURIComponent(patientId);
            dlgopen(url, 'pop-assignments', 'modal-lg', 850, '', '', {
                allowDrag: true,
                allowResize: true,
                sizeHeight: 'full',
            });
        }
    </script>

    <script src="js/custom_bindings.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/user_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/patient_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/therapy_group_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/tabs_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/application_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/frame_proxies.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/dialog_utils.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/shortcuts.js?v=<?php echo $v_js_includes; ?>"></script>
    <script>
        window.OPENEMR_AI_COPILOT_URL = <?php echo js_escape(OEGlobalsBag::getInstance()->getWebRoot() . '/interface/ai_copilot/index.php?embedded=1'); ?>;
        window.OPENEMR_AI_COPILOT_HEALTH_URL = <?php echo js_escape(OEGlobalsBag::getInstance()->getWebRoot() . '/interface/ai_copilot/index.php?embedded=1&healthcheck=1'); ?>;
        window.OPENEMR_AI_COPILOT_LOGIN_URL = <?php echo js_escape(OEGlobalsBag::getInstance()->getWebRoot() . '/interface/login/login.php'); ?>;
    </script>
    <script src="<?php echo attr_url(OEGlobalsBag::getInstance()->getWebRoot()); ?>/interface/ai_copilot/copilot_widget.js?v=<?php echo attr_url((string) ($v_js_includes ?? time())); ?>"></script>

    <?php
    // Below code block is to prepare certain elements for deciding what links to show on the menu
    // prepare Ensora eRx globals that are used in creating the menu
    if (OEGlobalsBag::getInstance()->getBoolean('erx_enable')) {
        $newcrop_user_role_sql = sqlQuery("SELECT `newcrop_user_role` FROM `users` WHERE `username` = ?", [$session->get('authUser')]);
        OEGlobalsBag::getInstance()->set('newcrop_user_role', $newcrop_user_role_sql['newcrop_user_role']);
        if (OEGlobalsBag::getInstance()->get('newcrop_user_role') === 'erxadmin') {
            OEGlobalsBag::getInstance()->set('newcrop_user_role_erxadmin', 1);
        }
    }

    // prepare track anything to be used in creating the menu
    $track_anything_sql = sqlQuery("SELECT `state` FROM `registry` WHERE `directory` = 'track_anything'");
    OEGlobalsBag::getInstance()->set('track_anything_state', $track_anything_sql['state'] ?? 0);
    // prepare Issues popup link global that is used in creating the menu
    OEGlobalsBag::getInstance()->set('allow_issue_menu_link', (AclMain::aclCheckCore('encounters', 'notes', '', 'write')
    || AclMain::aclCheckCore('encounters', 'notes_a', '', 'write'))
    && AclMain::aclCheckCore('patients', 'med', '', 'write'));

    // we use twig templates here so modules can customize some of these files
    // at some point we will twigify all of main.php so we can extend it.
    echo $twig->render("interface/main/tabs/tabs_template.html.twig", []);
    echo $twig->render("interface/main/tabs/menu_template.html.twig", []);
    // TODO: patient_data_template.php is a more extensive refactor that could be done in a future feature request but to not jeopardize 7.0.3 release we will hold off.
    ?>
    <?php require_once("templates/patient_data_template.php"); ?>
    <?php
    echo $twig->render("interface/main/tabs/therapy_group_template.html.twig", []);
    echo $twig->render("interface/main/tabs/user_data_template.html.twig", [
        'openemr_name' => OEGlobalsBag::getInstance()->getString('openemr_name')
    ]);
    // Collect the menu then build it
    $menuMain = new MainMenuRole(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher());
    $menu_restrictions = $menuMain->getMenu();
    echo $twig->render("interface/main/tabs/menu_json.html.twig", ['menu_restrictions' => $menu_restrictions]);
    ?>
    <?php $userQuery = sqlQuery("select * from users where username = ?", [$session->get('authUser')]); ?>

    <script>
        <?php
        if ($session->get('default_open_tabs')) :
            // For now, only the first tab is visible, this could be improved upon by further customizing the list options in a future feature request
            $visible = "true";
            $default_open_tabs = $session->get('default_open_tabs');
            foreach ($default_open_tabs as $i => $tab) :
                $_unsafe_url = preg_replace('/(\?.*)/m', '', Path::canonicalize($fileroot . DIRECTORY_SEPARATOR . $tab['notes']));
                if (realpath($_unsafe_url) === false || !str_starts_with($_unsafe_url, (string) $fileroot)) {
                    unset($default_open_tabs[$i]);
                    $session->set('default_open_tabs', $default_open_tabs);
                    continue;
                }
                $url = json_encode($webroot . "/" . $tab['notes']);
                $target = json_encode($tab['option_id']);
                $label = json_encode(xl("Loading") . " " . $tab['title']);
                $loading = xlj("Loading");
                echo "app_view_model.application_data.tabs.tabsList.push(new tabStatus($label, $url, $target, $loading, true, $visible, false));\n";
                $visible = "false";
            endforeach;
        endif;
        ?>

        app_view_model.application_data.user(new user_data_view_model(<?php echo json_encode($session->get("authUser"))
            . ',' . json_encode($userQuery['fname'])
            . ',' . json_encode($userQuery['lname'])
            . ',' . json_encode($session->get('authProvider')); ?>));
    </script>
    <style>
      :root {
        --openemr-shell-height: 100dvh;
      }

      html,
      body {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        min-height: 100% !important;
        height: 100% !important;
        overflow: hidden;
      }

      body {
        display: flex;
        flex-direction: column;
        margin: 0;
        overflow: hidden;
      }

      #mainBox {
        display: flex;
        flex: 1 1 auto;
        flex-direction: row;
        align-items: stretch;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        height: calc(var(--openemr-shell-height, 100dvh) - var(--patient-banner-height, 0px));
        min-height: 0;
        overflow: hidden;
        background: #eef3fb;
      }

      #mainSidebarNav {
        display: flex;
        flex: 0 0 17rem;
        flex-direction: column;
        align-items: stretch;
        width: 17rem;
        max-width: 17rem;
        min-width: 17rem;
        min-height: 0;
        height: 100%;
        padding: 0.9rem 0.8rem;
        gap: 0.8rem;
        overflow: visible;
        background: #0f2f6d;
        color: #ffffff;
        border-right: 1px solid rgba(255, 255, 255, 0.16);
        box-shadow: 14px 0 32px rgba(15, 23, 42, 0.12);
        z-index: 6;
        position: relative;
        transition: width 0.18s ease, min-width 0.18s ease, max-width 0.18s ease, padding 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
      }
      #patientBanner.patient-identity-banner {
        display: block;
        flex: 0 0 auto;
        width: 100%;
        box-sizing: border-box;
        border-bottom: 1px solid #d9dee8;
        background: #f8fafc;
        padding: 0.4rem 0.85rem;
        font-size: 0.875rem;
        line-height: 1.35;
        z-index: 20;
      }

      #patientBanner .patient-identity-banner__inner {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.65rem 1rem;
        width: 100%;
      }

      #patientBanner .patient-identity-banner__name {
        font-weight: 700;
        color: #0f172a;
        margin-right: 0.35rem;
      }

      #patientBanner .patient-identity-banner__item {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        color: #334155;
        white-space: nowrap;
      }

      #patientBanner .patient-identity-banner__label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #53627a;
        font-weight: 700;
        letter-spacing: 0.03em;
      }

      #patientBanner .patient-identity-banner__status {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        white-space: nowrap;
      }

      #patientBanner .patient-identity-banner__status-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.1rem 0.45rem;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
      }

      /* Optional: keep the banner readable on smaller screens */
      @media (max-width: 767.98px) {
        #patientBanner.patient-identity-banner {
          padding: 0.45rem 0.65rem;
          font-size: 0.82rem;
        }

        #patientBanner .patient-identity-banner__inner {
          gap: 0.4rem 0.75rem;
        }
      }

      #mainSidebarNav.navbar {
        flex-wrap: nowrap;
      }

      #mainSidebarNav .navbar-brand,
      #mainSidebarNav .navbar-toggler {
        flex-shrink: 0;
      }

      #mainSidebarNav .openemr-top-nav-identity {
        display: flex;
        align-items: center;
        position: relative;
        width: 100%;
        min-height: 3rem;
      }

      #mainSidebarNav .navbar-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 3rem;
        margin: 0;
        padding: 0.55rem 0.9rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.1);
      }

      #mainSidebarNav .openemr-top-nav-logo {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        max-width: calc(100% - 3.55rem);
      }

      #mainSidebarNav .navbar-brand img {
        display: block;
        height: 1rem;
      }

      #mainSidebarNav .navbar-toggler {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        margin: 0;
        padding: 0;
        border: 1px solid rgba(255, 255, 255, 0.32);
        border-radius: 0.95rem;
        background: rgba(255, 255, 255, 0.1);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16);
      }

      #mainSidebarNav .openemr-top-nav-toggle {
        position: relative;
        z-index: 1;
      }

      #mainSidebarNav .navbar-toggler-icon {
        filter: brightness(0) invert(1);
      }

      #mainMenu {
        order: 4;
        flex: 0 0 auto;
        width: 100%;
        min-width: 0;
        max-width: none;
      }

      #mainMenu.collapse:not(.show) {
        display: block;
      }

      #mainMenu > .appMenu {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        min-width: 0;
        max-width: none;
        gap: 0.3rem;
      }

      #mainMenu > .appMenu > div,
      #mainMenu > .appMenu > span,
      #mainMenu .menuSection {
        min-width: 0;
        width: 100%;
      }

      #mainMenu > .appMenu > div,
      #mainMenu > .appMenu > span {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: 0.55rem;
        margin: 0;
        min-height: 2.45rem;
        padding: 0 0.72rem;
        border-radius: 0.82rem;
        background: #0f2f6d;
        box-shadow: none;
        transition: transform 0.12s ease, background-color 0.12s ease;
      }

      #mainMenu > .appMenu > div:hover,
      #mainMenu > .appMenu > span:hover {
        background: #18408f;
        transform: translateX(2px);
      }

      #mainMenu .closeButton {
        float: none;
        position: static;
        top: auto;
        inset-inline-end: auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1rem;
        min-width: 1rem;
        margin: 0;
        color: #ffffff;
        font-size: 0.8rem;
      }

      #mainMenu .menuSection {
        position: relative;
        background: transparent;
      }

      #mainMenu .menuSection:hover {
        background: transparent;
      }

      #mainMenu > .appMenu > div .menuLabel,
      #mainMenu > .appMenu > span .menuLabel,
      #mainMenu > .appMenu > div .menuSection > .menuLabel {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 0;
        padding: 0.58rem 0;
        border-radius: 0;
        background: transparent;
        color: #ffffff;
        box-shadow: none;
        font-weight: 700;
        font-size: 0.9rem;
        line-height: 1.2;
        white-space: nowrap;
      }

      #mainMenu > .appMenu > div .menuLabel:hover,
      #mainMenu > .appMenu > span .menuLabel:hover,
      #mainMenu > .appMenu > div .menuSection > .menuLabel:hover {
        background: transparent;
        color: #ffffff;
      }

      form[name="frm_search_globals"] {
        order: 3;
        flex: 0 0 auto;
        width: 100%;
        min-width: 0;
        max-width: none;
        margin: 0;
      }

      .frm_search_globals,
      form[name="frm_search_globals"] .input-group,
      #anySearchBox {
        min-width: 0;
        max-width: 100%;
        width: 100%;
      }

      form[name="frm_search_globals"] .input-group {
        display: flex;
        flex-wrap: nowrap;
        align-items: stretch;
        gap: 0.4rem;
      }

      form[name="frm_search_globals"] .input-group-append {
        margin: 0;
      }

      #anySearchBox {
        flex: 1 1 auto;
        min-height: 2.85rem;
        border: 0;
        border-radius: 0.95rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
      }

      #search_globals {
        flex: 0 0 auto;
        min-width: 2.85rem;
        min-height: 2.85rem;
        border: 0;
        border-radius: 0.95rem;
        background: #ffffff;
        color: #0f2f6d;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
      }

      #search_globals:hover,
      #search_globals:focus {
        background: #e6efff;
        color: #0c2557;
      }

      #userData {
        order: 2;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: stretch;
        width: 100%;
        min-width: 0;
        max-width: none;
        margin: 0;
      }

      #userData > .appMenu {
        display: flex;
        align-items: center;
        justify-content: stretch;
        width: 100%;
        min-width: 0;
        max-width: none;
      }

      #username-container {
        width: 100%;
        margin: 0 !important;
      }

      #username {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        min-height: 3.2rem;
        margin-top: 5%;
        padding: 0.8rem 1rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 700;
      }

      #username .user-label-text {
        color: #ffffff;
        line-height: 1.2;
      }

      #userdropdown.dropdown-menu {
        white-space: nowrap;
        min-width: 15rem;
        max-width: min(20rem, calc(100vw - 1rem));
        overflow-x: auto;
      }

      #mainMenu .dropdown-toggle::after {
        margin-inline-start: 0.5rem;
      }

      #username.dropdown-toggle::after {
        display: none;
      }

      #mainMenu .menuSection > .menuEntries {
        position: absolute;
        top: 0;
        inset-inline-start: calc(100% - 0.18rem);
        min-width: 16rem;
        padding: 0.4rem;
        border: 1px solid rgba(15, 47, 109, 0.08);
        border-radius: 1rem;
        background: #ffffff;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.18);
      }

      #mainMenu .menuSection > .menuEntries::before {
        content: "";
        position: absolute;
        top: 0;
        bottom: 0;
        inset-inline-start: -0.85rem;
        width: 0.85rem;
      }

      #mainMenu .menuSection > .menuEntries .menuEntries {
        top: 0;
        inset-inline-start: calc(100% - 0.15rem);
      }

      #mainMenu .menuEntries li .menuLabel {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.72rem 0.9rem;
        border-radius: 0.8rem;
        color: #0f172a;
      }

      #mainMenu .menuEntries li .menuLabel:hover {
        background: #e6efff;
        color: #0f2f6d;
      }

      #mainShellContent {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        width: 100%;
        max-width: none;
        min-width: 0;
        min-height: 0;
        height: 100%;
        overflow: hidden;
      }

      #mainBox.site-nav-retracted #mainSidebarNav {
        flex-basis: 0;
        width: 0;
        max-width: 0;
        min-width: 0;
        padding-inline: 0;
        border-right: 0;
        box-shadow: none;
      }

      #mainBox.site-nav-retracted #mainSidebarNav > :not(.openemr-top-nav-identity) {
        opacity: 0;
        pointer-events: none;
      }

      #mainBox.site-nav-retracted #mainSidebarNav .openemr-top-nav-identity {
        min-width: 2.75rem;
      }

      #mainBox.site-nav-retracted #mainSidebarNav .openemr-top-nav-logo {
        opacity: 0;
        pointer-events: none;
      }

      #mainShellContent > div {
        width: 100%;
        max-width: 100%;
        min-width: 0;
      }

      #attendantData {
        flex-shrink: 0;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        overflow: visible;
        background: #f8fbff;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
      }

      #attendantData > .d-lg-inline-flex {
        min-width: 0;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      #attendantData .flex-fill,
      #attendantData .flex-column {
        min-width: 0;
      }

      #tabs_div {
        flex-shrink: 0;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
        background: #ffffff;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
      }

      .tabControls {
        display: flex;
        align-items: center;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        overflow-x: auto;
        overflow-y: hidden;
        white-space: nowrap;
        background: #ffffff;
      }

      .tabControls .tabSpan {
        flex: 0 0 auto;
      }

      .tabControls .tabsNoHover.w-100 {
        flex: 1 1 auto;
        min-width: 0;
      }

      #mainFrames_div,
      .mainFrames {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        min-height: 0;
        overflow: hidden;
      }

      #framesDisplay {
        display: flex;
        flex: 1 1 auto;
        flex-direction: row;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        min-height: 0;
        height: 100%;
        overflow: hidden;
      }

      #framesDisplay > div,
      .frameDisplay {
        position: relative;
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        min-height: 0;
        height: 100%;
        overflow: hidden;
      }

      #framesDisplay iframe,
      .frameDisplay iframe {
        display: block;
        flex: 1 1 auto;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        height: 100%;
        min-height: 0;
        border: 0;
      }

      @media (max-width: 1199.98px) {
        #mainBox {
          flex-direction: column;
        }

        #mainSidebarNav {
          flex: 0 0 auto;
          width: 100%;
          max-width: 100%;
          min-width: 0;
          height: auto;
          min-height: 0;
          padding: 0.8rem;
          gap: 0.75rem;
          overflow: visible;
          background: #0f2f6d;
        }

        #mainSidebarNav .openemr-top-nav-identity {
          min-height: 2.75rem;
        }

        #mainSidebarNav .navbar-toggler {
          box-shadow: none;
          margin-top: 1%;
        }

        #mainSidebarNav .openemr-top-nav-logo {
          max-width: calc(100% - 3.2rem);
          margin-top: 1%;
        }

        #mainBox.site-nav-retracted #mainSidebarNav {
          flex-basis: auto;
          width: 100%;
          max-width: 100%;
          min-width: 0;
          padding: 0.8rem;
          background: transparent;
          border-right-color: transparent;
          box-shadow: none;
        }

        #mainBox.site-nav-retracted #mainSidebarNav > :not(.openemr-top-nav-identity) {
          opacity: 1;
          pointer-events: auto;
        }

        #mainBox.site-nav-retracted #mainSidebarNav .openemr-top-nav-logo {
          opacity: 1;
          pointer-events: auto;
        }

        #mainMenu {
          order: 4;
          flex: 1 1 100%;
          width: 100%;
          max-width: 100%;
        }

        #mainMenu.collapse:not(.show) {
          display: none;
        }

        #mainMenu > .appMenu {
          width: 100%;
          flex-direction: column;
          align-items: stretch;
        }

        form[name="frm_search_globals"] {
          order: 3;
          flex: 1 1 100%;
          width: 100%;
          max-width: 100%;
        }

        #userData {
          order: 2;
          width: 100%;
          margin: 0;
          justify-content: stretch;
        }

        #mainMenu .menuSection > .menuEntries,
        #mainMenu .menuSection > .menuEntries .menuEntries {
          position: static;
          inset-inline-start: auto;
          top: auto;
          min-width: 0;
          width: 100%;
          margin-top: 0.35rem;
          box-shadow: inset 0 0 0 1px rgba(15, 47, 109, 0.08);
        }
      }

      @media (max-width: 767.98px) {
        body {
          overflow: hidden;
        }

        #mainBox {
          height: var(--openemr-shell-height, 100dvh);
        }

        #tabs_div {
          padding-top: 0.25rem;
        }
      }

      @media (min-width: 768px) and (max-width: 1199.98px) {
        #mainSidebarNav,
        #mainBox.site-nav-retracted #mainSidebarNav {
          min-height: 10dvh;
        }
      }
    </style>
</head>

<body class="min-vw-100">
    <?php
    // fire off an event here
    if (OEGlobalsBag::getInstance()->hasKernel()) {
        $dispatcher = OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher();
        $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_PRE);
    }
    ?>
    <!-- Below iframe is to support logout, which needs to be run in an inner iframe to work as intended -->
    <iframe name="logoutinnerframe" id="logoutinnerframe" style="visibility:hidden; position:absolute; left:0; top:0; height:0; width:0; border:none;" src="about:blank"></iframe>
    <?php // mdsupport - app settings
    $disp_mainBox = '';
    $app1 = $session->get('app1');
    if (!empty($app1)) {
        $rs = sqlquery(
            "SELECT title app_url FROM list_options WHERE activity=1 AND list_id=? AND option_id=?",
            ['apps', $app1]
        );
        if ($rs['app_url'] != "main/main_screen.php") {
            echo '<iframe name="app1" src="../../' . attr($rs['app_url']) . '"
            style="position: absolute; left: 0; top: 0; height: 100%; width: 100%; border: none;" />';
            $disp_mainBox = 'style="display: none;"';
        }
    }
    ?>
<div
    id="patientBanner"
    class="patient-identity-banner"
    data-testid="patient-identity-banner"
>
    <div class="patient-identity-banner__inner">
        <strong class="patient-identity-banner__name" data-patient-banner-field="name">No patient selected</strong>

        <span class="patient-identity-banner__item">
            <span class="patient-identity-banner__label">DOB:</span>
            <span data-patient-banner-field="dob">—</span>
        </span>

        <span class="patient-identity-banner__item">
            <span class="patient-identity-banner__label">Sex:</span>
            <span data-patient-banner-field="sex">Unknown</span>
        </span>

        <span class="patient-identity-banner__item">
            <span class="patient-identity-banner__label">MRN:</span>
            <span data-patient-banner-field="mrn">—</span>
        </span>

        <span class="patient-identity-banner__item">
            <span class="patient-identity-banner__label">Status:</span>
            <span class="badge badge-secondary patient-identity-banner__status patient-identity-banner__status-badge" data-patient-banner-field="status">No patient</span>
        </span>
    </div>
</div>
    <div id="mainBox" <?php echo $disp_mainBox ?>>
        <nav id="mainSidebarNav" class="navbar navbar-expand-xl navbar-dark py-0">
            <div class="openemr-top-nav-identity">
                <button class="navbar-toggler openemr-top-nav-toggle" type="button" data-toggle="collapse" data-target="#mainMenu" aria-controls="mainMenu" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <?php if (OEGlobalsBag::getInstance()->getBoolean('display_main_menu_logo')) {
                    $bag = OEGlobalsBag::getInstance();
                    $logoLinkDefault = 'https://www.open-emr.org/';
                    $logoTitleDefault = xl('OpenEMR Website');
                    $logoLink = trim($bag->getString('main_menu_logo_link', $logoLinkDefault));
                    $logoTitle = trim($bag->getString('main_menu_logo_title', $logoTitleDefault));
                    $logoImg = '<img src="' . attr($menuLogo) . '" class="d-inline-block align-middle" height="16" alt="' . xla('Main Menu Logo') . '">';
                    if ($logoLink !== '') {
                        echo '<a class="navbar-brand openemr-top-nav-logo" href="' . attr($logoLink) . '" title="' . attr($logoTitle) . '" rel="noopener" target="_blank">' . $logoImg . '</a>' . "\n";
                    } else {
                        echo '<span class="navbar-brand openemr-top-nav-logo">' . $logoImg . '</span>' . "\n";
                    }
                } ?>
            </div>
            <div class="collapse navbar-collapse" id="mainMenu" data-bind="template: {name: 'menu-template', data: application_data}"></div>
            <?php if (OEGlobalsBag::getInstance()->get('search_any_patient') != 'none') : ?>
                <form name="frm_search_globals" class="form-inline">
                    <div class="input-group">
                        <input type="text" id="anySearchBox" class="form-control-sm <?php echo $any_search_class ?> form-control" name="anySearchBox" placeholder="<?php echo xla("Search by any demographics") ?>" autocomplete="off">
                        <div class="input-group-append">
                            <button type="button" id="search_globals" class="btn btn-sm btn-secondary <?php echo $search_globals_class ?>" title='<?php echo xla("Search for patient by entering whole or part of any demographics field information"); ?>' data-bind="event: {mousedown: viewPtFinder.bind( $data, '<?php echo xla("The search field cannot be empty. Please enter a search term") ?>', '<?php echo attr($search_any_type); ?>')}">
                                <i class="fa fa-search">&nbsp;</i></button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
            <!--Below is the user data section that contains the user information and the attendant data-->
            <span id="userData" data-bind="template: {name: 'user-data-template', data: application_data}"></span>
            <?php
            // fire off a nav event
            $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_NAV);
            ?>
        </nav>
        <div id="mainShellContent">
            <div id="attendantData" class="body_title acck" data-bind="template: {name: app_view_model.attendant_template_type, data: application_data}"></div>
            <div class="body_title pt-1" id="tabs_div" data-bind="template: {name: 'tabs-controls', data: application_data}"></div>
            <div class="mainFrames d-flex flex-row" id="mainFrames_div">
                <div id="framesDisplay" data-bind="template: {name: 'tabs-frames', data: application_data}"></div>
            </div>
            <?php echo $twig->render("product_registration/product_registration_modal.html.twig", [
                'webroot' => $webroot,
                'allowEmail' => $allowEmail ?? false,
                'allowTelemetry' => $allowTelemetry ?? false]); ?>
        </div>
    </div>
    <div id="versionFooter" class="text-muted" style="position:fixed; bottom:4px; inset-inline-end:8px; font-size:11px; pointer-events:none; z-index:4;">
        <?php echo $softwareVersion; ?>
    </div>
    <script>
        function syncOpenEmrShellViewportHeight() {
            const banner = document.getElementById('patientBanner');
            const bannerHeight = banner ? banner.offsetHeight : 0;
            document.documentElement.style.setProperty('--openemr-shell-height', `${window.innerHeight}px`);
            document.documentElement.style.setProperty('--patient-banner-height', `${bannerHeight}px`);
        }

        function syncPatientBanner() {
            const banner = document.getElementById('patientBanner');
            if (!banner || !window.app_view_model || !app_view_model.application_data) {
                return;
            }

            const selectedPatient = app_view_model.application_data.patient
                ? app_view_model.application_data.patient()
                : null;

            const setField = function (field, value) {
                const node = banner.querySelector('[data-patient-banner-field="' + field + '"]');
                if (node) {
                    node.textContent = value;
                }
            };

            const readValue = function (value, fallback) {
                if (typeof value === 'function') {
                    const result = value();
                    return result || fallback;
                }
                return value || fallback;
            };

            const statusNode = banner.querySelector('[data-patient-banner-field="status"]');
            const setStatusClass = function (status) {
                if (!statusNode) {
                    return;
                }
                statusNode.classList.remove('badge-success', 'badge-warning', 'badge-danger', 'badge-secondary');
                switch ((status || '').toLowerCase()) {
                    case 'deceased':
                        statusNode.classList.add('badge-danger');
                        break;
                    case 'inactive':
                        statusNode.classList.add('badge-warning');
                        break;
                    case 'no patient':
                    case 'unknown':
                        statusNode.classList.add('badge-secondary');
                        break;
                    default:
                        statusNode.classList.add('badge-success');
                        break;
                }
            };

            if (!selectedPatient) {
                setField('name', 'No patient selected');
                setField('dob', '—');
                setField('sex', 'Unknown');
                setField('mrn', '—');
                setField('status', 'No patient');
                banner.classList.remove('has-selected-patient');
                setStatusClass('No patient');
                syncOpenEmrShellViewportHeight();
                return;
            }

            const statusValue = readValue(selectedPatient.active_status, 'Active');

            setField('name', readValue(selectedPatient.pname, 'Selected patient'));
            setField('dob', readValue(selectedPatient.str_dob, '—'));
            setField('sex', readValue(selectedPatient.sex, 'Unknown'));
            setField('mrn', readValue(selectedPatient.pubpid, readValue(selectedPatient.pid, '—')));
            setField('status', statusValue);
            banner.classList.add('has-selected-patient');
            setStatusClass(statusValue);
            syncOpenEmrShellViewportHeight();
        }

        let openEmrShellResizeTimer = null;

        function scheduleOpenEmrShellLayoutSync() {
            if (openEmrShellResizeTimer) {
                clearTimeout(openEmrShellResizeTimer);
            }
            openEmrShellResizeTimer = window.setTimeout(function () {
                syncPatientBanner();
                syncOpenEmrShellViewportHeight();
            }, 50);
        }

        window.syncPatientBanner = syncPatientBanner;
        window.syncOpenEmrShellViewportHeight = syncOpenEmrShellViewportHeight;
        window.scheduleOpenEmrShellLayoutSync = scheduleOpenEmrShellLayoutSync;

        ko.applyBindings(app_view_model);
        if (app_view_model.application_data.patient && typeof app_view_model.application_data.patient.subscribe === 'function') {
            app_view_model.application_data.patient.subscribe(syncPatientBanner);
        }

        $(function () {
            syncPatientBanner();
            syncOpenEmrShellViewportHeight();
            $(window).on('resize orientationchange', scheduleOpenEmrShellLayoutSync);
            $(window).on('resize orientationchange', function () {
                if (window.innerWidth < 1200) {
                    $('#mainBox').removeClass('site-nav-retracted');
                    $('#mainSidebarNav .navbar-toggler').attr('aria-expanded', $('#mainMenu').hasClass('show') ? 'true' : 'false');
                }
            });
            $('.dropdown-toggle').dropdown();
            $('#patient_caret').click(function () {
                $('#attendantData').slideToggle(150, function () {
                    scheduleOpenEmrShellLayoutSync();
                });
                $('#patient_caret').toggleClass('fa-caret-down').toggleClass('fa-caret-up');
            });
            $('#mainMenu').on('shown.bs.collapse hidden.bs.collapse', scheduleOpenEmrShellLayoutSync);
            $('#mainMenu, #tabs_div').on('click', 'a, button', scheduleOpenEmrShellLayoutSync);
            $('#mainSidebarNav .navbar-toggler').on('click', function (event) {
                if (window.innerWidth >= 1200) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    $('#mainBox').toggleClass('site-nav-retracted');
                    const isExpanded = !$('#mainBox').hasClass('site-nav-retracted');
                    $(this).attr('aria-expanded', isExpanded ? 'true' : 'false');
                    scheduleOpenEmrShellLayoutSync();
                }
            });
            if ($('body').css('direction') == "rtl") {
                $('.dropdown-menu-right').each(function () {
                    $(this).removeClass('dropdown-menu-right');
                });
            }
        });
        $(function () {
            $('#logo_menu').focus();
        });
        $('#anySearchBox').keypress(function (event) {
            if (event.which === 13 || event.keyCode === 13) {
                event.preventDefault();
                $('#search_globals').mousedown();
            }
        });
        document.addEventListener('touchstart', {}); //specifically added for iOS devices, especially in iframes
        $(function () {
            goRepeaterServices();
        });
    </script>
    <?php

    // fire off an event here
    $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_POST);

    if ($allowRegisterDialog !== false) { // disable if running unit tests.
        // Include the product registration js, telemetry and usage data reporting dialog
        echo $twig->render("product_registration/product_reg.js.twig", ['webroot' => $webroot]);
    }

    ?>
</body>

</html>
