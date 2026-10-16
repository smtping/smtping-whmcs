<?php
/**
 * SMTPing Email Verifier for WHMCS
 *
 * Blocks invalid, spamtrap, disposable and other risky addresses at checkout,
 * registration and profile edits, and verifies existing clients in bulk.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Store.php';

use Smtping\Whmcs\Client;
use Smtping\Whmcs\Store;
use WHMCS\Database\Capsule;

function smtping_config()
{
    return [
        'name'        => 'SMTPing Email Verifier',
        'description' => 'Block invalid, spamtrap, disposable and other risky email addresses at checkout and registration, and verify your existing clients in bulk.',
        'author'      => 'SMTPing',
        'language'    => 'english',
        'version'     => '1.0.0',
        'fields'      => [
            'api_key' => ['FriendlyName' => 'API key', 'Type' => 'password', 'Size' => '60', 'Description' => 'Create a key in the <a href="https://app.smtping.com" target="_blank" rel="noopener">SMTPing dashboard</a>, under API keys. 25 free credits per day.'],
            'check_checkout' => ['FriendlyName' => 'Check new clients at checkout', 'Type' => 'yesno', 'Default' => 'yes'],
            'check_register' => ['FriendlyName' => 'Check client registration', 'Type' => 'yesno', 'Default' => 'yes'],
            'check_profile' => ['FriendlyName' => 'Check email changes', 'Type' => 'yesno', 'Default' => 'yes', 'Description' => 'When a client changes the email address in the client area.'],
            'check_admin' => ['FriendlyName' => 'Also check admin edits', 'Type' => 'yesno', 'Description' => 'Off by default, so staff can always save a client.'],
            'block_bands' => ['FriendlyName' => 'Addresses to block', 'Type' => 'dropdown', 'Options' => 'Avoid only,Avoid and judgement', 'Default' => 'Avoid only', 'Description' => 'Avoid: invalid, spamtrap, disposable, blacklisted, complainer, spambot, inbox_full. Judgement adds catch-all, unknown and role.'],
            'message' => ['FriendlyName' => 'Error message', 'Type' => 'text', 'Size' => '60', 'Default' => 'Please use a valid, permanent email address.'],
            'timeout' => ['FriendlyName' => 'Timeout (seconds)', 'Type' => 'text', 'Size' => '4', 'Default' => '8', 'Description' => 'If SMTPing does not answer in time, the address goes through.'],
            'log_blocked' => ['FriendlyName' => 'Log blocked addresses', 'Type' => 'yesno', 'Default' => 'yes', 'Description' => 'Adds a line to the Activity Log for each blocked address.'],
        ],
    ];
}

function smtping_activate()
{
    try {
        Store::install();
        return ['status' => 'success', 'description' => 'SMTPing Email Verifier is active. Paste your API key in the module settings.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Could not create the cache table: ' . $e->getMessage()];
    }
}

function smtping_deactivate()
{
    try {
        Store::uninstall();
        Capsule::table('tbladdonmodules')->where('module', 'smtping')->where('setting', 'jobs')->delete();
    } catch (\Exception $e) {
    }
    return ['status' => 'success', 'description' => 'SMTPing Email Verifier was deactivated and its data removed.'];
}

function smtping_jobs()
{
    $raw = Capsule::table('tbladdonmodules')->where('module', 'smtping')->where('setting', 'jobs')->value('value');
    $jobs = json_decode((string)$raw, true);
    return is_array($jobs) ? $jobs : [];
}

function smtping_save_jobs(array $jobs)
{
    Capsule::table('tbladdonmodules')->updateOrInsert(['module' => 'smtping', 'setting' => 'jobs'], ['value' => json_encode($jobs)]);
}

function smtping_output($vars)
{
    $link = $vars['modulelink'];
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $key = Store::apiKey();
    $notice = '';

    if ($key === '') {
        echo '<div class="alert alert-warning">Paste your SMTPing API key in <a href="configaddonmods.php">Addon Modules &gt; SMTPing Email Verifier &gt; Configure</a>.</div>';
        return;
    }
    $client = new Client($key);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smtping_action'])) {
        check_token('WHMCS.admin.default');
        try {
            $action = $_POST['smtping_action'];
            $jobs = smtping_jobs();
            if ($action === 'start') {
                $scope = isset($_POST['scope']) ? $_POST['scope'] : 'active';
                $q = Capsule::table('tblclients');
                if ($scope === 'active') {
                    $q->where('status', 'Active');
                } elseif ($scope === '30d') {
                    $q->where('datecreated', '>=', date('Y-m-d', time() - 30 * 86400));
                }
                $emails = array_values(array_unique(array_filter(array_map(function ($m) { return strtolower(trim($m)); }, $q->pluck('email')->all()))));
                $emails = array_values(array_diff($emails, array_keys(Store::getMany($emails))));
                if (!$emails) {
                    $notice = '<div class="alert alert-info">Every client in this selection was verified in the last 30 days.</div>';
                } else {
                    foreach (array_chunk($emails, Client::BULK_MAX) as $chunk) {
                        $job = $client->createBulk($chunk);
                        $id = isset($job['jobId']) ? (string)$job['jobId'] : '';
                        if ($id === '') {
                            throw new \Exception('The API did not return a job id.');
                        }
                        $jobs[$id] = ['scope' => $scope, 'count' => count($chunk), 'created' => date('Y-m-d H:i'), 'status' => 'Queued'];
                    }
                    smtping_save_jobs($jobs);
                    $notice = '<div class="alert alert-success">Verification started for ' . count($emails) . ' addresses. Come back in a few minutes and click Fetch results.</div>';
                }
            } elseif ($action === 'fetch' && isset($jobs[$_POST['job_id']])) {
                $id = $_POST['job_id'];
                $state = $client->getBulk($id);
                $status = isset($state['status']) ? (string)$state['status'] : '';
                $jobs[$id]['status'] = $status;
                if (strcasecmp($status, 'Succeeded') === 0) {
                    $counts = ['safe' => 0, 'judgement' => 0, 'avoid' => 0];
                    foreach ($client->bulkResults($id) as $row) {
                        $email = strtolower(trim(isset($row['email']) ? (string)$row['email'] : ''));
                        if ($email === '') {
                            continue;
                        }
                        $st = isset($row['status']) ? (string)$row['status'] : 'unknown';
                        $band = Client::band($st);
                        Store::set($email, $st, $band);
                        $counts[$band]++;
                    }
                    $jobs[$id]['status'] = 'Done';
                    $jobs[$id]['result'] = $counts;
                    $notice = '<div class="alert alert-success">Results saved. safe: ' . $counts['safe'] . ', judgement: ' . $counts['judgement'] . ', avoid: ' . $counts['avoid'] . '.</div>';
                } else {
                    $notice = '<div class="alert alert-info">Job still ' . $e(strtolower($status)) . ': ' . (int)(isset($state['processedEmails']) ? $state['processedEmails'] : 0) . ' of ' . (int)$jobs[$id]['count'] . ' processed.</div>';
                }
                smtping_save_jobs($jobs);
            } elseif ($action === 'forget') {
                unset($jobs[$_POST['job_id']]);
                smtping_save_jobs($jobs);
            }
        } catch (\Exception $ex) {
            $notice = '<div class="alert alert-danger">SMTPing: ' . $e($ex->getMessage()) . '</div>';
        }
    }

    $credits = null;
    try {
        $credits = $client->credits();
    } catch (\Exception $ex) {
        $notice .= '<div class="alert alert-warning">SMTPing: ' . $e($ex->getMessage()) . '</div>';
    }

    $token = generate_token('form');
    echo $notice;
    echo '<p>Remaining credits: <strong>' . $e($credits !== null && isset($credits['remaining']) ? $credits['remaining'] : '?') . '</strong>. 1 credit per address; unknown results are free.</p>';

    echo '<h2>Verify existing clients</h2>';
    echo '<p>Addresses verified in the last 30 days are skipped.</p>';
    echo '<form method="post" action="' . $e($link) . '" class="form-inline">' . $token . '<input type="hidden" name="smtping_action" value="start">';
    echo '<select name="scope" class="form-control"><option value="active">Active clients</option><option value="30d">Clients created in the last 30 days</option><option value="all">All clients</option></select> ';
    echo '<button type="submit" class="btn btn-primary">Start verification</button></form>';

    $jobs = smtping_jobs();
    if ($jobs) {
        echo '<table class="table table-striped" style="margin-top:16px"><thead><tr><th>Selection</th><th>Addresses</th><th>Started</th><th>Status</th><th>Result</th><th></th></tr></thead><tbody>';
        foreach (array_reverse($jobs, true) as $id => $j) {
            $res = isset($j['result']) ? 'safe ' . (int)$j['result']['safe'] . ', judgement ' . (int)$j['result']['judgement'] . ', avoid ' . (int)$j['result']['avoid'] : '';
            $act = (isset($j['status']) && $j['status'] === 'Done') ? ['forget', 'Remove'] : ['fetch', 'Fetch results'];
            echo '<tr><td>' . $e($j['scope']) . '</td><td>' . (int)$j['count'] . '</td><td>' . $e($j['created']) . '</td><td>' . $e($j['status']) . '</td><td>' . $e($res) . '</td><td>';
            echo '<form method="post" action="' . $e($link) . '" style="display:inline">' . $token . '<input type="hidden" name="smtping_action" value="' . $act[0] . '"><input type="hidden" name="job_id" value="' . $e($id) . '"><button type="submit" class="btn btn-default btn-xs">' . $act[1] . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    $risky = Capsule::table('tblclients as c')
        ->join(Store::TABLE . ' as s', 's.email', '=', Capsule::raw('LOWER(c.email)'))
        ->whereIn('s.band', Store::blockedBands())
        ->orderBy('c.id', 'desc')
        ->limit(500)
        ->get(['c.id', 'c.firstname', 'c.lastname', 'c.email', 'c.status as cstatus', 's.status', 's.checked_at']);

    echo '<h2 style="margin-top:24px">Clients with a risky address</h2>';
    if (!count($risky)) {
        echo '<p>None found among the verified clients.</p>';
        return;
    }
    echo '<p>' . count($risky) . ' clients, newest first. Review them before sending invoices or service emails.</p>';
    echo '<table class="table table-striped"><thead><tr><th>Client</th><th>Email</th><th>Client status</th><th>SMTPing verdict</th><th>Checked</th></tr></thead><tbody>';
    foreach ($risky as $r) {
        echo '<tr><td><a href="clientssummary.php?userid=' . (int)$r->id . '">' . $e(trim($r->firstname . ' ' . $r->lastname)) . '</a></td><td>' . $e($r->email) . '</td><td>' . $e($r->cstatus) . '</td><td>' . $e($r->status) . '</td><td>' . $e($r->checked_at) . '</td></tr>';
    }
    echo '</tbody></table>';
}
