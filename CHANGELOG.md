# Değişiklikler

## 3.0.2 (2026-11-17)

### Güvenlik

- Token önbelleği anahtarına şifre katıldı. Önceden aynı abone numarasıyla yanlış şifre veren bir
  `Otp` nesnesi önbellekteki geçerli token'ı kullanabiliyordu (birden fazla hesabın aynı cache'i
  paylaştığı uygulamalar).
- Şifre ve token, istisnaların çağrı yığını argümanlarında gizlenir (`#[\SensitiveParameter]`,
  PHP 8.2+). Önceden `zend.exception_ignore_args=Off` olan ortamlarda hata kayıtlarına düşebiliyordu.
- `var_dump`, `print_r` ve `dump()` çıktısında şifre gösterilmez.

### Dokümanlar

- `429 RATE_LIMITED` (OTP gönderim sınırı) hata tablosuna eklendi.
- 2.x hata kodları: 60 → `422 INSUFFICIENT_CREDIT`, 80 → `429 RATE_LIMITED`.

## 3.0.1 (2026-11-17)

- Dokümanda alıcı biçimi E.164 (`+905XXXXXXXXX`) olarak belirtildi. Kodda değişiklik yok.

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
