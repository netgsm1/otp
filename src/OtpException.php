<?php

declare(strict_types=1);

namespace Netgsm\Otp;

use RuntimeException;

/**
 * Netgsm API'nin döndürdüğü hata ya da bağlantı hatası.
 *
 * Hata mantığını HTTP durum koduna değil errorCode'a göre kurun.
 * Destek talebinde requestId'yi paylaşın.
 */
final class OtpException extends RuntimeException
{
    /**
     * @param int $httpStatus bağlantı hatasında 0
     * @param string $errorCode SMS API'de INVALID_REQUEST, REJECTED_HEADER...; token alırken invalid_credentials, ip_not_allowed...
     * @param list<array{field: string, message: string}> $violations yalnızca gövde doğrulama hatasında dolu
     * @param bool $mayHaveBeenSent true ise OTP gitmiş olabilir; otomatik olarak yeniden göndermeyin
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly ?string $requestId = null,
        public readonly array $violations = [],
        public readonly bool $mayHaveBeenSent = false,
        string $detail = '',
    ) {
        parent::__construct(trim("Netgsm $httpStatus $errorCode $detail") . ($requestId !== null ? " (requestId $requestId)" : ''));
    }

    /** SMS API ({code, message, requestId, violations}) ya da Identity ({code, detail, request_id}) hata gövdesinden. */
    public static function fromResponse(int $status, array $json, bool $mayHaveBeenSent): self
    {
        $code = $json['code'] ?? null;
        $requestId = $json['requestId'] ?? $json['request_id'] ?? null;
        $detail = $json['message'] ?? $json['detail'] ?? '';

        return new self(
            $status,
            is_string($code) ? $code : "HTTP_$status",
            is_string($requestId) ? $requestId : null,
            is_array($json['violations'] ?? null) ? $json['violations'] : [],
            $mayHaveBeenSent,
            is_string($detail) ? $detail : '',
        );
    }
}
