<?php

namespace Smtping\Whmcs;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Settings and the 30-day result cache, so an address is never paid for twice.
 */
class Store
{
    const TABLE = 'mod_smtping_cache';
    const TTL_DAYS = 30;

    private static $settings = null;

    public static function setting($name, $default = '')
    {
        if (self::$settings === null) {
            self::$settings = [];
            try {
                foreach (Capsule::table('tbladdonmodules')->where('module', 'smtping')->get() as $row) {
                    self::$settings[$row->setting] = $row->value;
                }
            } catch (\Exception $e) {
            }
        }
        return isset(self::$settings[$name]) && self::$settings[$name] !== '' ? self::$settings[$name] : $default;
    }

    public static function on($name)
    {
        return self::setting($name) === 'on';
    }

    public static function apiKey()
    {
        return trim((string)self::setting('api_key'));
    }

    public static function blockedBands()
    {
        return self::setting('block_bands', 'Avoid only') === 'Avoid and judgement' ? ['avoid', 'judgement'] : ['avoid'];
    }

    public static function install()
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, function ($table) {
                $table->string('email', 190)->primary();
                $table->string('status', 32);
                $table->string('band', 16);
                $table->dateTime('checked_at')->index();
            });
        }
    }

    public static function uninstall()
    {
        Capsule::schema()->dropIfExists(self::TABLE);
    }

    public static function get($email)
    {
        $rows = self::getMany([$email]);
        return isset($rows[$email]) ? $rows[$email] : null;
    }

    public static function getMany(array $emails)
    {
        $out = [];
        if (!$emails) {
            return $out;
        }
        $cutoff = date('Y-m-d H:i:s', time() - self::TTL_DAYS * 86400);
        foreach (array_chunk($emails, 500) as $chunk) {
            $rows = Capsule::table(self::TABLE)->whereIn('email', $chunk)->where('checked_at', '>=', $cutoff)->get();
            foreach ($rows as $row) {
                $out[$row->email] = ['status' => $row->status, 'band' => $row->band];
            }
        }
        return $out;
    }

    public static function set($email, $status, $band)
    {
        Capsule::table(self::TABLE)->updateOrInsert(
            ['email' => strtolower(trim($email))],
            ['status' => substr($status, 0, 32), 'band' => $band, 'checked_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Returns the error message to show, or '' when the address may go through.
     * Cached results are reused. Any API error lets the address through.
     */
    public static function blockMessage($email)
    {
        static $seen = [];
        $email = strtolower(trim((string)$email));
        if ($email === '' || strpos($email, '@') === false || self::apiKey() === '') {
            return '';
        }
        if (!isset($seen[$email])) {
            $r = self::get($email);
            if ($r === null) {
                try {
                    $r = (new Client(self::apiKey(), (int)self::setting('timeout', '8')))->verify($email);
                    self::set($email, $r['status'], $r['band']);
                } catch (\Exception $e) {
                    logActivity('SMTPing: check skipped for ' . $email . ' (' . $e->getMessage() . ')');
                    $r = ['status' => 'error', 'band' => 'safe'];
                }
            }
            $seen[$email] = $r;
        }
        if (!in_array($seen[$email]['band'], self::blockedBands(), true)) {
            return '';
        }
        if (self::on('log_blocked')) {
            logActivity('SMTPing: blocked ' . $email . ' (' . $seen[$email]['status'] . ')');
        }
        return (string)self::setting('message', 'Please use a valid, permanent email address.');
    }
}
