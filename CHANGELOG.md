# Değişiklikler

## 3.0.0 (2026-11-16)

Uyumu bozan sürüm. Geçiş: [UPGRADE.md](UPGRADE.md).

### Güvenlik

- XML injection: mesaj, numara ve başlık XML'e kaçışsız ekleniyordu. v3 JSON kullanır.
- TLS sertifika doğrulaması açıldı.
- Şifre artık her gönderimde değil, yalnızca token alırken gönderilir.
- `baseUrl` HTTPS olmalıdır; düz HTTP yalnızca yerel adresler için kabul edilir.

### Değişenler

- Netgsm SMS API v3'e (`POST /sms/v3/send/otp`) geçildi.
- Sınıf `Otp`, metot `send()`; başarıda `jobId`, hatada `OtpException`.
- Tanınmayan yanıtlar artık başarılı sayılmıyor.
- Token isteğe bağlı PSR-16 cache'te tutulur.
- `401`'de bir kez yeniden giriş, `503`'te iki tekrar. `500`'de tekrar yok.
- Eksik ayar `InvalidArgumentException` fırlatır.
- PHP 8.1 gerekir.

### Eklenenler

- Kurumsal OTP hesapları için `enterprise`, ayrıca `appName` ve `timeout` seçenekleri.
- PHPUnit testleri, `LICENSE`.
