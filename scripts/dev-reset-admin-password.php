#!/usr/bin/env php
<?php

/**
 * Dev-only CLI utility to reset a local OpenEMR user's password using the
 * same hashing stack as the standard login system.
 *
 * Usage:
 *   php scripts/dev-reset-admin-password.php
 *   php scripts/dev-reset-admin-password.php --site=default --username=admin-openemr --password='OpenEMR2026!'
 */

declare(strict_types=1);

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\UserService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

const DEFAULT_SITE = 'default';
const DEFAULT_USERNAME = 'admin-openemr';
const DEFAULT_PASSWORD = 'OpenEMR2026!';
const DEFAULT_LOGIN_URL = 'http://localhost:8300/interface/login/login.php?site=%s';

/**
 * @return array{site:string,username:string,password:string}
 */
function collectArguments(): array
{
    $options = getopt('', ['site::', 'username::', 'password::', 'help']);

    if (isset($options['help'])) {
        $usage = <<<TEXT
Usage:
  php scripts/dev-reset-admin-password.php [--site=default] [--username=admin-openemr] [--password='OpenEMR2026!']

Defaults:
  --site=default
  --username=admin-openemr
  --password=OpenEMR2026!

TEXT;
        fwrite(STDOUT, $usage);
        exit(0);
    }

    $site = trim((string) ($options['site'] ?? DEFAULT_SITE));
    $username = trim((string) ($options['username'] ?? DEFAULT_USERNAME));
    $password = (string) ($options['password'] ?? DEFAULT_PASSWORD);

    if ($site === '' || preg_match('/[^A-Za-z0-9\\-.]/', $site)) {
        fwrite(STDERR, "Invalid site value.\n");
        exit(1);
    }

    if ($username === '') {
        fwrite(STDERR, "Username can not be empty.\n");
        exit(1);
    }

    if ($password === '') {
        fwrite(STDERR, "Password can not be empty.\n");
        exit(1);
    }

    return [
        'site' => $site,
        'username' => $username,
        'password' => $password,
    ];
}

/**
 * @return list<string>
 */
function listAvailableAdminUsernames(): array
{
    $usernames = [];
    $result = sqlStatement(
        "SELECT DISTINCT u.username
           FROM users u
           JOIN gacl_aro aro
             ON aro.value = u.username
            AND aro.section_value = 'users'
           JOIN gacl_groups_aro_map gg
             ON gg.aro_id = aro.id
           JOIN gacl_aro_groups gag
             ON gag.id = gg.group_id
          WHERE u.username != ''
            AND u.active = 1
            AND gag.value = 'admin'
          ORDER BY u.username"
    );

    while ($row = sqlFetchArray($result)) {
        if (!empty($row['username'])) {
            $usernames[] = (string) $row['username'];
        }
    }

    if ($usernames !== []) {
        return $usernames;
    }

    $fallback = sqlStatement(
        "SELECT username
           FROM users
          WHERE username != ''
            AND active = 1
            AND authorized = 1
          ORDER BY username"
    );

    while ($row = sqlFetchArray($fallback)) {
        if (!empty($row['username'])) {
            $usernames[] = (string) $row['username'];
        }
    }

    return $usernames;
}

/**
 * @return array<string, bool>
 */
function getUsersSecureColumns(): array
{
    $columns = [];
    $result = sqlStatement("SHOW COLUMNS FROM `users_secure`");
    while ($row = sqlFetchArray($result)) {
        if (!empty($row['Field'])) {
            $columns[(string) $row['Field']] = true;
        }
    }
    return $columns;
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $currentSecure
 * @return array{0:string,1:list<mixed>}
 */
function buildUsersSecureUpdateSql(array $columns, array $currentSecure, string $newHash, int $userId): array
{
    $assignments = [];
    $params = [];

    if (isset($columns['last_update_password'])) {
        $assignments[] = "`last_update_password` = NOW()";
    }
    if (isset($columns['login_fail_counter'])) {
        $assignments[] = "`login_fail_counter` = 0";
    }
    if (isset($columns['last_login_fail'])) {
        $assignments[] = "`last_login_fail` = NULL";
    }
    if (isset($columns['auto_block_emailed'])) {
        $assignments[] = "`auto_block_emailed` = 0";
    }
    if (isset($columns['password'])) {
        $assignments[] = "`password` = ?";
        $params[] = $newHash;
    }

    $passwordHistory = OEGlobalsBag::getInstance()->get('password_history');
    $historyEnabled = $passwordHistory !== 0 && $passwordHistory !== '0' && $passwordHistory !== null && $passwordHistory !== '';
    if ($historyEnabled) {
        if (isset($columns['password_history1'])) {
            $assignments[] = "`password_history1` = ?";
            $params[] = $currentSecure['password'] ?? null;
        }
        if (isset($columns['password_history2'])) {
            $assignments[] = "`password_history2` = ?";
            $params[] = $currentSecure['password_history1'] ?? null;
        }
        if (isset($columns['password_history3'])) {
            $assignments[] = "`password_history3` = ?";
            $params[] = $currentSecure['password_history2'] ?? null;
        }
        if (isset($columns['password_history4'])) {
            $assignments[] = "`password_history4` = ?";
            $params[] = $currentSecure['password_history3'] ?? null;
        }
    }

    $params[] = $userId;
    $sql = "UPDATE `users_secure` SET " . implode(', ', $assignments) . " WHERE `id` = ?";

    return [$sql, $params];
}

/**
 * @param array<string, bool> $columns
 * @return array{0:string,1:list<mixed>}
 */
function buildUsersSecureInsertSql(array $columns, int $userId, string $username, string $hash): array
{
    $fieldNames = [];
    $placeholders = [];
    $params = [];

    $append = static function (string $field, mixed $value) use (&$fieldNames, &$placeholders, &$params): void {
        $fieldNames[] = "`{$field}`";
        if ($value === '__NOW__') {
            $placeholders[] = 'NOW()';
            return;
        }
        if ($value === '__NULL__') {
            $placeholders[] = 'NULL';
            return;
        }
        $placeholders[] = '?';
        $params[] = $value;
    };

    foreach (['id' => $userId, 'username' => $username, 'password' => $hash] as $field => $value) {
        if (isset($columns[$field])) {
            $append($field, $value);
        }
    }
    if (isset($columns['last_update_password'])) {
        $append('last_update_password', '__NOW__');
    }
    if (isset($columns['login_fail_counter'])) {
        $append('login_fail_counter', 0);
    }
    if (isset($columns['last_login_fail'])) {
        $append('last_login_fail', '__NULL__');
    }
    if (isset($columns['auto_block_emailed'])) {
        $append('auto_block_emailed', 0);
    }

    $sql = "INSERT INTO `users_secure` (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', $placeholders) . ")";
    return [$sql, $params];
}

$args = collectArguments();
$site = $args['site'];
$username = $args['username'];
$newPassword = $args['password'];

$repoRoot = dirname(__DIR__);
$siteDir = $repoRoot . '/sites/' . $site;
if (!is_dir($siteDir)) {
    fwrite(STDERR, "Site '{$site}' does not exist at {$siteDir}.\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = realpath($repoRoot) ?: $repoRoot;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:8300';
$_SERVER['REQUEST_SCHEME'] = $_SERVER['REQUEST_SCHEME'] ?? 'http';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_GET['site'] = $site;

$ignoreAuth = true;
$sessionAllowWrite = true;
$fake_register_globals = false;
$sanitize_all_escapes = true;

require_once $repoRoot . '/vendor/autoload.php';

$session = SessionWrapperFactory::getInstance()->getCoreSession();
$session->set('site_id', $site);

require_once $repoRoot . '/interface/globals.php';

$userService = new UserService();
$user = $userService->getUserByUsername($username);
if (empty($user) || empty($user['id'])) {
    $availableAdmins = listAvailableAdminUsernames();
    $message = "User '{$username}' was not found in site '{$site}'.";
    if ($availableAdmins !== []) {
        $message .= "\nAvailable admin usernames: " . implode(', ', $availableAdmins);
    } else {
        $message .= "\nNo active admin usernames were found.";
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$userId = (int) $user['id'];
if (empty($user['uuid'])) {
    UuidRegistry::createMissingUuidForRow('users', 'id', $userId);
}

$authGroup = $userService->getAuthGroupForUser($username);
$aclGroups = AclExtended::aclGetGroupTitles($username);
if (empty($authGroup) || empty($aclGroups)) {
    fwrite(
        STDERR,
        "User '{$username}' exists but is missing required OpenEMR group or ACL membership, so login may fail.\n"
    );
    exit(1);
}

$hashBuilder = new AuthHash();
$passwordForHashing = $newPassword;
$newHash = $hashBuilder->passwordHash($passwordForHashing);
unset($passwordForHashing);
if (empty($newHash) || !AuthHash::hashValid((string) $newHash)) {
    fwrite(STDERR, "Failed to create a valid OpenEMR password hash.\n");
    exit(1);
}

$usersSecureColumns = getUsersSecureColumns();
$currentSecure = privQuery(
    "SELECT `id`, `username`, `password`, `password_history1`, `password_history2`, `password_history3`, `password_history4`
       FROM `users_secure`
      WHERE `id` = ?",
    [$userId]
);

sqlStatement(
    "UPDATE `users`
        SET `authorized` = 1,
            `active` = 1
      WHERE `id` = ?",
    [$userId]
);

if (empty($currentSecure['id'])) {
    [$insertSql, $insertParams] = buildUsersSecureInsertSql($usersSecureColumns, $userId, $username, (string) $newHash);
    sqlStatement($insertSql, $insertParams);
    $secureAction = 'inserted';
} else {
    [$updateSql, $updateParams] = buildUsersSecureUpdateSql($usersSecureColumns, $currentSecure, (string) $newHash, $userId);
    sqlStatement($updateSql, $updateParams);
    $secureAction = 'updated';
}

$loginUrl = sprintf(DEFAULT_LOGIN_URL, rawurlencode($site));
$result = privQuery(
    "SELECT u.id, u.username, u.authorized, u.active, us.last_update_password, us.login_fail_counter
       FROM `users` u
       JOIN `users_secure` us
         ON us.id = u.id
      WHERE u.id = ?",
    [$userId]
);

fwrite(STDOUT, "OpenEMR dev password reset complete.\n");
fwrite(STDOUT, "Site: {$site}\n");
fwrite(STDOUT, "Username: {$username}\n");
fwrite(STDOUT, "users_secure row: {$secureAction}\n");
fwrite(STDOUT, "User active: " . (($result['active'] ?? 0) == 1 ? 'yes' : 'no') . "\n");
fwrite(STDOUT, "User authorized: " . (($result['authorized'] ?? 0) == 1 ? 'yes' : 'no') . "\n");
fwrite(STDOUT, "Auth group: {$authGroup}\n");
fwrite(STDOUT, "Login fail counter reset to: " . (string) ($result['login_fail_counter'] ?? 'n/a') . "\n");
fwrite(STDOUT, "Login URL: {$loginUrl}\n");

exit(0);
