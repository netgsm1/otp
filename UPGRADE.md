# 2.x'ten 3.0'a geçiş

3.0, Netgsm'in eski XML OTP servisi yerine SMS API v3'ü kullanır. Paketin genel API'si değişti; 2.x
kodunuz güncellenmeden çalışmaz.

## Neden

- 2.x mesajı XML'e kaçışsız ekliyordu: `&` ya da `<` içeren bir metin gönderimi bozuyordu ve dışarıdan
  gelen veriyle isteğe XML öğesi eklenebiliyordu.
- 2.x TLS sertifikasını doğrulamıyordu ve şifreyi her istekte gönderiyordu. Araya giren biri şifreyi
  okuyabiliyordu.
- 2.x tanımadığı her yanıtı "Gönderim başarılı." sayıyordu.

## Adımlar

1. PHP'yi 8.1 ya da üzerine yükseltin.
2. Paketi güncelleyin:

   ```bash
   composer require netgsm/otp:^3.0
   ```

3. `.env` değişkenlerinin adları değişmedi (`NETGSM_USERCODE`, `NETGSM_PASSWORD`, `NETGSM_HEADER`).
   Şifre olarak API alt kullanıcısının şifresi önerilir. Sunucunuzun IP adresinin API erişim izni
   olmalıdır.
4. Kodunuzu güncelleyin (aşağıda).
5. Web uygulamasıysa bir token önbelleği verin: [README → Token önbelleği](README.md#token-önbelleği).
   Vermezseniz her gönderim yeni token alır ve yoğun anda `429 rate_limited` alırsınız.
6. `jobId`'yi sayı sütununda saklıyorsanız sütunu `VARCHAR`'a çevirin. 2.x'te 10 haneliydi, v3'te
   25 haneden uzun (`1794840287454900001878633`); `INT` ve `BIGINT` sütunlara sığmaz.
7. Sunucunuzda TLS doğrulaması çalışıyor mu deneyin. Windows, XAMPP ve WAMP'ta genellikle CA paketi
   tanımlı değildir: [README → Sorun giderme](README.md#sorun-giderme).

## Kod

2.x:

```php
use Netgsm\Otp\otp;

$islem = new otp;
$sonuc = $islem->otp(['message' => 'Kodunuz: 1234', 'no' => '553XXXXXXX', 'header' => 'BASLIK']);

if (isset($sonuc['jobid'])) {
    $jobId = $sonuc['jobid'];
} else {
    error_log($sonuc['durum']);
}
```

3.0:

```php
use Netgsm\Otp\Otp;
use Netgsm\Otp\OtpException;

$otp = new Otp(cache: $cache);

try {
    $jobId = $otp->send('553XXXXXXX', 'Kodunuz: 1234', 'BASLIK');
} catch (OtpException $e) {
    error_log($e->getMessage());
}
```

| 2.x | 3.0 |
|---|---|
| `Netgsm\Otp\otp` | `Netgsm\Otp\Otp` |
| `->otp(['message' => ..., 'no' => ..., 'header' => ...])` | `->send($recipient, $text, $sender = null)` |
| Başarıda `['durum' => 'Gönderim başarılı.', 'jobid' => ...]` | `string` jobId |
| Hatada `['durum' => ..., 'code' => ...]` | `OtpException` |
| Ayar eksikse `'x'` ile gönderim | `InvalidArgumentException` |
| `api.netgsm.com.tr/sms/send/otp` (XML) | `ext-api.netgsm.com.tr/sms/v3/send/otp` (JSON, token) |

2.x'teki `553XXXXXXX` numara biçimi 3.0'da da geçerlidir.

## Davranış farkları

- Türkçe karakterler Latin karşılığına çevrilir (`ğ` → `g`); emoji gibi desteklenmeyen karakterler `?`
  olur. Metin en fazla 155 karakterdir (abone numarasıyla 160).
- 2.x'teki dakikada 100 gönderim sınırı (kod 80) v3 OTP ucunda tanımlı değil. Token isteği ise IP
  başına dakikada 30 ile sınırlıdır; bu yüzden token önbelleği gerekir.
- `503` alındığında paket iki kez tekrar dener. `500` ve bağlantı hatasında tekrar denemez;
  `mayHaveBeenSent` OTP'nin gitmiş olabileceğini gösterir.

## Eski hata kodları

Kodunuz 2.x'in sayısal kodlarına göre karar veriyorsa yeni karşılıkları:

| 2.x | Anlamı | 3.0 `errorCode` |
|---|---|---|
| 20 | Mesaj metni ya da boyu hatalı | `400 INVALID_REQUEST` |
| 30 | Kullanıcı adı, şifre, API izni ya da IP | Token alırken `401 invalid_credentials`, `403 ip_not_allowed`; gönderimde `403 FORBIDDEN` |
| 40, 41 | Gönderici adı hatalı | `422 REJECTED_HEADER` |
| 50 | Numara hatalı | `400 INVALID_REQUEST` (`recipient is not a valid number`) |
| 60 | OTP paketi tanımlı değil | Yaklaşık karşılık: `422 INSUFFICIENT_CREDIT` ya da `403 FORBIDDEN` (`account is not activated for this operation`) |
| 70 | Parametre hatası | `400 INVALID_REQUEST` (`violations` alanı belirtir) |
| 80 | Dakikada 100 gönderim sınırı aşıldı | Karşılığı yok: v3 OTP ucunda istek sınırı tanımlı değil |
| 100 | Sistem hatası | `500 INTERNAL_ERROR` ya da `503 SERVICE_UNAVAILABLE` |

Tüm kodlar: [README → Hata yönetimi](README.md#hata-yönetimi).
