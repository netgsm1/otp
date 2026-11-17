<?php

declare(strict_types=1);

namespace Netgsm\Otp\Tests;

use InvalidArgumentException;
use Netgsm\Otp\Otp;
use Netgsm\Otp\OtpException;
use PHPUnit\Framework\TestCase;

final class OtpTest extends TestCase
{
    private const OTP = '/sms/v3/send/otp';
    private const TOKEN = '/auth/v3/token';

    private static $server;
    private static string $baseUrl;
    private static string $stateFile;

    private ArrayCache $cache;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        self::$baseUrl = "http://127.0.0.1:$port";
        self::$stateFile = tempnam(sys_get_temp_dir(), 'otp-stub');

        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/stub/router.php'],
            [1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']],
            $pipes,
            null,
            ['OTP_STUB_STATE' => self::$stateFile] + getenv(),
        );

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Stub sunucu başlamadı');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        @unlink(self::$stateFile);
    }

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testSendsOtpAndReturnsJobId(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('123')]]);

        $jobId = $this->otp()->send('+905061234567', 'Kodunuz: 1234 & 5 dk <geçerli>');

        self::assertSame('123', $jobId);
        [$login, $send] = $this->log();
        self::assertSame(['username' => '8500000000', 'password' => 'secret', 'scope' => 'sms'], $login['body']);
        self::assertSame('Bearer t1', $send['headers']['authorization']);
        self::assertSame(
            ['sender' => 'TESTHEADER', 'text' => 'Kodunuz: 1234 & 5 dk <geçerli>', 'recipient' => '+905061234567'],
            $send['body'],
        );
        self::assertArrayNotHasKey('x-client', $send['headers']);
    }

    public function testReusesCachedTokenAcrossInstances(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('1'), self::accepted('2')]]);

        $this->otp()->send('+905061234567', 'a');
        $this->otp()->send('+905061234567', 'b');

        self::assertSame([self::TOKEN, self::OTP, self::OTP], array_column($this->log(), 'path'));
    }

    public function testKeepsTokenInMemoryWithoutCache(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('1'), self::accepted('2')]]);

        // Süreç içi bellek statiktir; başka testlerle çakışmasın diye ayrı kullanıcı.
        (new TestOtp('memory-user', 'secret', 'TESTHEADER', null, self::$baseUrl))->send('+905061234567', 'a');
        (new TestOtp('memory-user', 'secret', 'TESTHEADER', null, self::$baseUrl))->send('+905061234567', 'b');

        self::assertSame([self::TOKEN, self::OTP, self::OTP], array_column($this->log(), 'path'));
    }

    public function testExpiredTokenTriggersNewLogin(): void
    {
        $this->stub([self::TOKEN => [self::token('t1', 1), self::token('t2')], self::OTP => [self::accepted('1'), self::accepted('2')]]);

        $this->otp()->send('+905061234567', 'a');
        sleep(1);
        $this->otp()->send('+905061234567', 'b');

        $log = $this->log();
        self::assertSame([self::TOKEN, self::OTP, self::TOKEN, self::OTP], array_column($log, 'path'));
        self::assertSame('Bearer t2', $log[3]['headers']['authorization']);
    }

    public function testLogsInAgainOnceAfter401(): void
    {
        $this->stub([
            self::TOKEN => [self::token('t1'), self::token('t2')],
            self::OTP => [self::error(401, 'UNAUTHENTICATED'), self::accepted('9')],
        ]);

        self::assertSame('9', $this->otp()->send('+905061234567', 'a'));
        self::assertSame('Bearer t2', $this->log()[3]['headers']['authorization']);
    }

    public function testSecond401IsThrown(): void
    {
        $this->stub([
            self::TOKEN => [self::token('t1'), self::token('t2')],
            self::OTP => [self::error(401, 'UNAUTHENTICATED'), self::error(401, 'UNAUTHENTICATED')],
        ]);

        $e = $this->sendExpectingError();
        self::assertSame('UNAUTHENTICATED', $e->errorCode);
        self::assertCount(4, $this->log());
    }

    public function testRetries503TwiceThenSucceeds(): void
    {
        $this->stub([
            self::TOKEN => [self::token('t1')],
            self::OTP => [self::error(503, 'SERVICE_UNAVAILABLE'), self::error(503, 'SERVICE_UNAVAILABLE'), self::accepted('7')],
        ]);

        $otp = $this->otp();
        self::assertSame('7', $otp->send('+905061234567', 'a'));
        self::assertSame([1, 2], $otp->pauses);
    }

    public function testGivesUpAfterThree503s(): void
    {
        $this->stub([
            self::TOKEN => [self::token('t1')],
            self::OTP => array_fill(0, 3, self::error(503, 'SERVICE_UNAVAILABLE')),
        ]);

        $e = $this->sendExpectingError();
        self::assertSame(503, $e->httpStatus);
        self::assertFalse($e->mayHaveBeenSent);
    }

    public function testDoesNotRetry500(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::error(500, 'INTERNAL_ERROR')]]);

        $e = $this->sendExpectingError();
        self::assertSame('INTERNAL_ERROR', $e->errorCode);
        self::assertSame('abc123', $e->requestId);
        self::assertTrue($e->mayHaveBeenSent);
        self::assertCount(2, $this->log());
    }

    public function testExposesViolations(): void
    {
        $violations = [['field' => 'text', 'message' => 'must not be blank']];
        $this->stub([
            self::TOKEN => [self::token('t1')],
            self::OTP => [['status' => 400, 'body' => ['code' => 'INVALID_REQUEST', 'message' => '1 field(s) rejected', 'requestId' => 'r1', 'violations' => $violations]]],
        ]);

        $e = $this->sendExpectingError();
        self::assertSame('INVALID_REQUEST', $e->errorCode);
        self::assertSame($violations, $e->violations);
        self::assertStringContainsString('1 field(s) rejected', $e->getMessage());
    }

    public function testLoginFailureStopsBeforeSending(): void
    {
        $this->stub([self::TOKEN => [['status' => 401, 'body' => ['code' => 'invalid_credentials', 'detail' => 'Kullanıcı adı veya şifre hatalı.', 'request_id' => 'id-1']]]]);

        $e = $this->sendExpectingError();
        self::assertSame('invalid_credentials', $e->errorCode);
        self::assertSame('id-1', $e->requestId);
        self::assertFalse($e->mayHaveBeenSent);
        self::assertSame([self::TOKEN], array_column($this->log(), 'path'));
    }

    public function testEnterpriseHeaderAndAppName(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('1')]]);

        (new TestOtp('8500000000', 'secret', 'TESTHEADER', $this->cache, self::$baseUrl, enterprise: true, appName: 'shop'))
            ->send('+905061234567', 'a');

        $send = $this->log()[1];
        self::assertSame('enterprise', $send['headers']['x-client']);
        self::assertSame('shop', $send['body']['appName']);
    }

    public function testSenderCanBeOverriddenPerCall(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('1')]]);

        $this->otp()->send('+905061234567', 'a', 'OTHER');

        self::assertSame('OTHER', $this->log()[1]['body']['sender']);
    }

    public function testReadsCredentialsFromEnv(): void
    {
        $this->stub([self::TOKEN => [self::token('t1')], self::OTP => [self::accepted('1')]]);
        $_ENV['NETGSM_USERCODE'] = 'env-user';
        $_ENV['NETGSM_PASSWORD'] = 'env-pass';
        $_ENV['NETGSM_HEADER'] = 'ENVHEADER';

        try {
            (new TestOtp(cache: $this->cache, baseUrl: self::$baseUrl))->send('+905061234567', 'a');
        } finally {
            unset($_ENV['NETGSM_USERCODE'], $_ENV['NETGSM_PASSWORD'], $_ENV['NETGSM_HEADER']);
        }

        [$login, $send] = $this->log();
        self::assertSame('env-user', $login['body']['username']);
        self::assertSame('ENVHEADER', $send['body']['sender']);
    }

    public function testMissingCredentialsFailFast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Otp(password: 'x');
    }

    public function testMissingSenderFailsBeforeAnyRequest(): void
    {
        $this->stub([]);
        try {
            (new Otp('u', 'p', null, $this->cache, self::$baseUrl))->send('+905061234567', 'a');
            self::fail('InvalidArgumentException bekleniyordu');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->log());
        }
    }

    public function testRejectsPlainHttpForRemoteHosts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Otp('u', 'p', 'H', null, 'http://ext-api.netgsm.com.tr');
    }

    public function testConnectionFailureIsNotReportedAsSent(): void
    {
        $otp = new Otp('u', 'p', 'H', $this->cache, 'http://127.0.0.1:' . self::freePort());

        try {
            $otp->send('+905061234567', 'a');
            self::fail('OtpException bekleniyordu');
        } catch (OtpException $e) {
            self::assertSame('NETWORK_ERROR', $e->errorCode);
            self::assertFalse($e->mayHaveBeenSent);
        }
    }

    public function testWrongPasswordDoesNotReuseCachedToken(): void
    {
        $this->stub([
            self::TOKEN => [self::token('t1'), self::error(401, 'invalid_credentials')],
            self::OTP => [self::accepted('1')],
        ]);
        $this->otp()->send('+905061234567', 'a');

        try {
            (new TestOtp('8500000000', 'wrong', 'TESTHEADER', $this->cache, self::$baseUrl))->send('+905061234567', 'b');
            self::fail('OtpException bekleniyordu');
        } catch (OtpException $e) {
            self::assertSame('invalid_credentials', $e->errorCode);
        }
        self::assertSame([self::TOKEN, self::OTP, self::TOKEN], array_column($this->log(), 'path'));
    }

    public function testDebugOutputHidesPassword(): void
    {
        $otp = $this->otp();

        self::assertStringNotContainsString('secret', print_r($otp, true));
        self::assertStringNotContainsString('secret', var_export($otp->__debugInfo(), true));
    }

    public function testStackTraceHidesSecrets(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new Otp('u', 'secret', 'H', $this->cache, 'http://127.0.0.1:' . self::freePort()))->send('+905061234567', 'a');
            self::fail('OtpException bekleniyordu');
        } catch (OtpException $e) {
            $frames = array_filter($e->getTrace(), fn ($f) => ($f['class'] ?? '') === Otp::class);
            self::assertNotEmpty($frames);
            self::assertStringContainsString('SensitiveParameterValue', print_r($frames, true));
            self::assertStringNotContainsString('secret', print_r($frames, true));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    private function otp(): TestOtp
    {
        return new TestOtp('8500000000', 'secret', 'TESTHEADER', $this->cache, self::$baseUrl);
    }

    private function sendExpectingError(): OtpException
    {
        try {
            $this->otp()->send('+905061234567', 'a');
        } catch (OtpException $e) {
            return $e;
        }
        self::fail('OtpException bekleniyordu');
    }

    private function stub(array $queue): void
    {
        $this->call('/__reset', json_encode((object) $queue));
    }

    private function log(): array
    {
        return json_decode($this->call('/__log'), true);
    }

    private function call(string $path, ?string $body = null): string
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        return (string) curl_exec($ch);
    }

    private static function token(string $access, int $expiresIn = 86400): array
    {
        return ['status' => 200, 'body' => ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'refresh_token' => 'r', 'scope' => 'sms']];
    }

    private static function accepted(string $jobId): array
    {
        return ['status' => 202, 'body' => ['jobId' => $jobId]];
    }

    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['code' => $code, 'message' => 'x', 'requestId' => 'abc123', 'violations' => null]];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}

/** Beklemeleri kaydeder, uyumaz. */
final class TestOtp extends Otp
{
    public array $pauses = [];

    protected function pause(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}
