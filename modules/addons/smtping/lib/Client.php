<?php

namespace Smtping\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal SMTPing API client using cURL. No dependencies.
 */
class Client
{
    const BASE_URL = 'https://api.smtping.com/api/v1';
    const USER_AGENT = 'smtping-whmcs/1.0.0';
    const BULK_MAX = 100000;

    const SAFE = ['valid', 'alias'];
    const AVOID = ['invalid', 'spamtrap', 'disposable', 'blacklisted', 'complainer', 'spambot', 'inbox_full'];

    private $apiKey;
    private $timeout;

    public function __construct($apiKey, $timeout = 60)
    {
        $this->apiKey = (string)$apiKey;
        $this->timeout = (int)$timeout;
    }

    public static function band($status)
    {
        if (in_array($status, self::SAFE, true)) {
            return 'safe';
        }
        if (in_array($status, self::AVOID, true)) {
            return 'avoid';
        }
        return 'judgement';
    }

    public function verify($email)
    {
        $r = $this->request('POST', '/verify/single', ['email' => $email], 1);
        $status = (string)(isset($r['status']) ? $r['status'] : 'unknown');
        return ['status' => $status, 'band' => self::band($status)];
    }

    public function createBulk(array $emails)
    {
        return $this->request('POST', '/verify/bulk', ['emails' => array_values($emails)]);
    }

    public function getBulk($jobId)
    {
        return $this->request('GET', '/verify/bulk/' . rawurlencode($jobId));
    }

    public function bulkResults($jobId)
    {
        $data = $this->request('GET', '/verify/bulk/' . rawurlencode($jobId) . '/result');
        if (isset($data['results']) && is_array($data['results'])) {
            $data = $data['results'];
        }
        return array_values(array_filter($data, 'is_array'));
    }

    public function credits()
    {
        return $this->request('GET', '/credits');
    }

    private function request($method, $path, $body = null, $maxRetries = 3)
    {
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init(self::BASE_URL . $path);
            $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json', 'User-Agent: ' . self::USER_AGENT];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $text = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($text === false) {
                if ($attempt < $maxRetries) {
                    usleep($this->backoff($attempt + 1));
                    continue;
                }
                throw new \Exception('Network error: ' . $error);
            }
            if ($code >= 200 && $code < 300) {
                $data = json_decode((string)$text, true);
                return is_array($data) ? $data : [];
            }
            if (($code === 429 || $code >= 500) && $attempt < $maxRetries) {
                usleep($this->backoff($attempt + 1));
                continue;
            }
            $data = json_decode((string)$text, true);
            $message = is_array($data) ? (isset($data['error']) ? $data['error'] : (isset($data['message']) ? $data['message'] : null)) : null;
            throw new \Exception(is_string($message) ? $message : 'SMTPing API returned HTTP ' . $code, $code);
        }
    }

    private function backoff($attempt)
    {
        return (int)(min(1000 * pow(2, $attempt - 1), 8000) + mt_rand(0, 250)) * 1000;
    }
}
