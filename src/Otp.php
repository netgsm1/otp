<?php

declare(strict_types=1);

namespace Netgsm\Otp;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Netgsm SMS API v3 ile OTP gönderimi.
 *
 * @see https://api-docs.netgsm.com.tr/sms/sending/send-otp/
 */
class Otp
{
    /** 503 sonrası tekrarlardan önce beklenecek süreler (sn). 503'te hiçbir şey gönderilmemiştir. */
    private const RETRY_DELAYS = [1, 2];

    /** Bu cURL hatalarında istek sunucuya ulaşmamıştır. */
    private const NOT_SENT_CURL_ERRORS = [
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_CACERT,
    ];

    /** Cache verilmediğinde token süreç içinde burada tutulur. */
    private static array $memory = [];

    private readonly string $username;
    private readonly string $password;
    private readonly ?string $sender;
    private readonly string $baseUrl;

    public function __construct(
        ?string $username = null,
        ?string $password = null,
        ?string $sender = null,
        private readonly ?CacheInterface $cache = null,
        string $baseUrl = 'https://ext-api.netgsm.com.tr',
        private readonly bool $enterprise = false,
        private readonly ?string $appName = null,
        private readonly int $timeout = 30,
    ) {
        $this->username = $username ?? self::env('NETGSM_USERCODE')
            ?? throw new InvalidArgumentException('Abone numarası yok: NETGSM_USERCODE tanımlayın ya da constructor ile verin.');
        $this->password = $password ?? self::env('NETGSM_PASSWORD')
            ?? throw new InvalidArgumentException('Şifre yok: NETGSM_PASSWORD tanımlayın ya da constructor ile verin.');
        $this->sender = $sender ?? self::env('NETGSM_HEADER');

        $baseUrl = rtrim($baseUrl, '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $local = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        if (!str_starts_with($baseUrl, 'https://') && !($local && str_starts_with($baseUrl, 'http://'))) {
            // Şifre ve token düz HTTP üzerinden gönderilmez; HTTP yalnızca yerel mock için.
            throw new InvalidArgumentException("baseUrl HTTPS olmalı: $baseUrl");
        }
        $this->baseUrl = $baseUrl;
    }

    /**
     * Tek alıcıya OTP gönderir.
     *
     * @param string $recipient 5XXXXXXXXX, 05XXXXXXXXX, 905XXXXXXXXX ya da +905XXXXXXXXX
     * @param string|null $sender verilmezse NETGSM_HEADER / constructor'daki gönderici
     *
     * @return string jobId
     *
     * @throws OtpException API bir hata döndürdüğünde ya da bağlantı kurulamadığında
     */
    public function send(string $recipient, string $text, ?string $sender = null): string
    {
        $sender ??= $this->sender;
        if ($sender === null || trim($sender) === '') {
            throw new InvalidArgumentException('Gönderici yok: NETGSM_HEADER tanımlayın ya da send() ile verin.');
        }
        if (trim($recipient) === '' || trim($text) === '') {
            throw new InvalidArgumentException('Alıcı ve metin boş olamaz.');
        }

        $body = ['sender' => $sender, 'text' => $text, 'recipient' => $recipient];
        if ($this->appName !== null) {
            $body['appName'] = $this->appName;
        }
        $headers = $this->enterprise ? ['x-client: enterprise'] : [];

        $relogged = false;
        $retries = 0;
        while (true) {
            $token = $this->token();
            [$status, $json] = $this->post('/sms/v3/send/otp', $body, $token, $headers);

            if ($status === 202) {
                if (is_string($json['jobId'] ?? null) && $json['jobId'] !== '') {
                    return $json['jobId'];
                }
                throw new OtpException($status, 'INVALID_RESPONSE', null, [], true, 'Yanıtta jobId yok');
            }
            if ($status === 401 && !$relogged) {
                $relogged = true;
                $this->forgetToken($token);
                continue;
            }
            if ($status === 503 && $retries < count(self::RETRY_DELAYS)) {
                $this->pause(self::RETRY_DELAYS[$retries++]);
                continue;
            }
            // 500 ve beklenmeyen 5xx: OTP gönderilmiş olabilir, otomatik tekrar yok.
            throw OtpException::fromResponse($status, $json, $status >= 500 && $status !== 503);
        }
    }

    /** Testlerde beklemeyi atlamak için ezilebilir. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function token(): string
    {
        $key = $this->cacheKey();
        $stored = $this->cache !== null ? $this->cache->get($key) : (self::$memory[$key] ?? null);
        if (is_array($stored) && is_string($stored['access_token'] ?? null) && time() < ($stored['expires_at'] ?? 0)) {
            return $stored['access_token'];
        }

        [$status, $json] = $this->post('/auth/v3/token', [
            'username' => $this->username,
            'password' => $this->password,
            'scope' => 'sms',
        ]);
        if ($status !== 200 || !is_string($json['access_token'] ?? null) || !is_int($json['expires_in'] ?? null)) {
            throw OtpException::fromResponse($status, $json, false);
        }

        // Süre dolmadan, ömrün son %10'unda (en fazla 5 dk) yeniden giriş yapılır.
        // Refresh kullanılmaz: refresh oturumun önceki token'ını düşürür ve süreçler arası kilit ister.
        $life = $json['expires_in'];
        $ttl = max(1, $life - min(300, intdiv($life, 10)));
        $stored = ['access_token' => $json['access_token'], 'expires_at' => time() + $ttl];
        if ($this->cache !== null) {
            $this->cache->set($key, $stored, $ttl);
        } else {
            self::$memory[$key] = $stored;
        }

        return $stored['access_token'];
    }

    /** Başka bir istek token'ı zaten yenilediyse yenisini silmez. */
    private function forgetToken(string $staleToken): void
    {
        $key = $this->cacheKey();
        if ($this->cache !== null) {
            if (($this->cache->get($key)['access_token'] ?? null) === $staleToken) {
                $this->cache->delete($key);
            }
        } elseif ((self::$memory[$key]['access_token'] ?? null) === $staleToken) {
            unset(self::$memory[$key]);
        }
    }

    private function cacheKey(): string
    {
        return 'netgsm_otp_' . sha1($this->baseUrl . '|' . $this->username);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, array} HTTP durum kodu ve JSON gövde
     */
    private function post(string $path, array $body, ?string $token = null, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);

        if ($raw === false) {
            $sent = $path === '/sms/v3/send/otp' && !in_array(curl_errno($ch), self::NOT_SENT_CURL_ERRORS, true);
            throw new OtpException(0, 'NETWORK_ERROR', null, [], $sent, curl_error($ch));
        }

        $json = json_decode((string) $raw, true);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_array($json) ? $json : []];
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
