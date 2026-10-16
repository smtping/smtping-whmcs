<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Store.php';

use Smtping\Whmcs\Store;
use WHMCS\Database\Capsule;

/**
 * New client at checkout. Existing clients who log in are not checked.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (!Store::on('check_checkout')) {
        return [];
    }
    $custtype = isset($vars['custtype']) ? $vars['custtype'] : (isset($_POST['custtype']) ? $_POST['custtype'] : '');
    if ($custtype === 'existing' || !empty($_SESSION['uid'])) {
        return [];
    }
    $email = isset($vars['email']) ? $vars['email'] : (isset($_POST['email']) ? $_POST['email'] : '');
    $message = Store::blockMessage($email);
    return $message !== '' ? [$message] : [];
});

/**
 * Registration and profile edits in the client area. Admin edits are skipped
 * unless enabled, and a profile edit is checked only when the email changes.
 */
add_hook('ClientDetailsValidation', 1, function ($vars) {
    if (defined('ADMINAREA') && !Store::on('check_admin')) {
        return [];
    }
    $email = isset($vars['email']) ? strtolower(trim((string)$vars['email'])) : '';
    if ($email === '') {
        return [];
    }
    $clientId = !empty($vars['userid']) ? (int)$vars['userid'] : (!defined('ADMINAREA') && !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0);
    if ($clientId > 0) {
        if (!Store::on('check_profile')) {
            return [];
        }
        $current = strtolower(trim((string)Capsule::table('tblclients')->where('id', $clientId)->value('email')));
        if ($current === $email) {
            return [];
        }
    } elseif (!Store::on('check_register')) {
        return [];
    }
    $message = Store::blockMessage($email);
    return $message !== '' ? [$message] : [];
});
