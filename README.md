# netgsm/otp

Netgsm SMS API v3 ile tek alıcıya OTP (doğrulama kodu, tek kullanımlık şifre) SMS'i gönderir.
OTP mesajları toplu SMS'ten ayrı bir kanaldan anında iletilir; zamanlanamaz.

2.x'ten geçiyorsanız önce [UPGRADE.md](UPGRADE.md) dosyasını okuyun.

## Gereksinimler

- PHP 8.2 ya da üzeri, `ext-curl`, `ext-json` (PHP 8.1 için 3.0.x sürümünü kullanın)
- Netgsm hesabınızda OTP SMS hizmeti
- API erişimi açık bir kullanıcı (API alt kullanıcısı önerilir) ve sunucunuzun IP adresi için API
  erişim izni. Portalda: Abonelik İşlemleri → API İşlemleri. Ayrıntılar
  [Bilgi Bankası](https://bilgibankasi.netgsm.com.tr/)'nda.

## Kurulum

```bash
composer require netgsm/otp
```

`.env`:

```dotenv
NETGSM_USERCODE=312XXXXXXX     # abone numaranız
NETGSM_PASSWORD=               # API alt kullanıcısının şifresi
NETGSM_HEADER=BASLIGINIZ       # hesabınıza kayıtlı gönderici adı
```

Değerleri ortam değişkeni yerine constructor ile de verebilirsiniz (aşağıda).

## Kullanım

```php
use Netgsm\Otp\Otp;
use Netgsm\Otp\OtpException;

$otp = new Otp(cache: $cache); // $cache: bkz. "Token önbelleği"

try {
    $jobId = $otp->send('+905XXXXXXXXX', 'Dogrulama kodunuz: 123456');
} catch (OtpException $e) {
    // bkz. "Hata yönetimi"
}
```

`send()` başarılı olursa `jobId` döner. `jobId` metindir ve 25 haneden uzun olabilir; veritabanında
sayı sütununda değil `VARCHAR` sütunda saklayın. Bir sorunu bildirirken bu değeri paylaşın.

## Token önbelleği

Paket, gönderimden önce Netgsm'den bir erişim token'ı alır ve süresi dolana kadar (24 saat) aynı
token'ı kullanır. Token'ın nerede tutulacağını `cache` parametresi belirler:

- **`cache` verilmezse** token PHP sürecinin belleğinde tutulur. Bu yalnızca uzun süre çalışan
  süreçlerde (kuyruk worker'ı, daemon) işe yarar.
- **Web uygulamalarında `cache` vermeniz gerekir.** PHP her web isteğinde belleği sıfırlar; cache
  yoksa her OTP gönderimi yeni bir token alır. Token isteği IP başına dakikada 30 ile sınırlıdır, yani
  yoğun bir anda gönderimler `429 rate_limited` hatasıyla durur.

`cache` herhangi bir [PSR-16](https://www.php-fig.org/psr/psr-16/) (`Psr\SimpleCache\CacheInterface`)
uygulaması olabilir.

**Laravel.** `Cache::store()` PSR-16 uyumludur. `php artisan config:cache` çalıştırıldığında `.env`
okunmadığından kimlik bilgilerini config üzerinden verin:

```php
// config/services.php
'netgsm' => [
    'usercode' => env('NETGSM_USERCODE'),
    'password' => env('NETGSM_PASSWORD'),
    'header' => env('NETGSM_HEADER'),
],

// AppServiceProvider::register()
$this->app->singleton(\Netgsm\Otp\Otp::class, fn () => new \Netgsm\Otp\Otp(
    username: config('services.netgsm.usercode'),
    password: config('services.netgsm.password'),
    sender: config('services.netgsm.header'),
    cache: \Illuminate\Support\Facades\Cache::store(),
));
```

**Symfony.** Bir cache havuzunu `Psr16Cache` ile sarın:

```php
use Symfony\Component\Cache\Psr16Cache;

$otp = new Otp(cache: new Psr16Cache($cachePool)); // ör. cache.app servisi
```

**Framework'süz.** `symfony/cache` gibi bir PSR-16 kütüphanesi kullanın:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$otp = new Otp(cache: new Psr16Cache(new FilesystemAdapter('netgsm', 0, '/var/cache/app')));
```

Notlar:

- Cache'teki token 24 saat geçerli bir erişim anahtarıdır. Dosya cache'i kullanıyorsanız dizin web'den
  erişilemez olmalı.
- Cache anahtarı adres, abone numarası ve şifreden üretilir. Aynı abone numarası farklı bir şifreyle
  verilirse önbellekteki token kullanılmaz, yeniden giriş yapılır.
- Birden fazla sunucuda her sunucunun kendi cache'i de çalışır (her biri ayrı oturum açar). Redis gibi
  paylaşılan bir cache yalnızca giriş sayısını azaltır.
- Token'ın süresi dolmadan yeni giriş yapılır. Netgsm token'ı daha önce geçersiz sayarsa (`401`) paket
  bir kez yeniden giriş yapıp isteği tekrarlar.

## Seçenekler

| Parametre | Varsayılan | Açıklama |
|---|---|---|
| `username` | `NETGSM_USERCODE` | Abone numarası |
| `password` | `NETGSM_PASSWORD` | API alt kullanıcısının şifresi |
| `sender` | `NETGSM_HEADER` | Gönderici adı. `send()`'in üçüncü parametresiyle gönderim bazında değiştirilebilir. |
| `cache` | `null` | PSR-16 cache |
| `baseUrl` | `https://ext-api.netgsm.com.tr` | Sandbox için `https://ext-sandbox.netgsm.com.tr`. HTTPS zorunludur; düz HTTP yalnızca `127.0.0.1` ve `localhost` için kabul edilir. |
| `enterprise` | `false` | Yalnızca kurumsal OTP hizmeti tanımlı hesaplar `true` verir (`x-client: enterprise` başlığı). Hesabınıza uymayan değer `403 FORBIDDEN` alır. |
| `appName` | `null` | Gönderimi yapan uygulamanın adı; yalnızca kayıt amaçlı. |
| `timeout` | `30` | İstek başına süre sınırı (sn) |

## Alıcı, gönderici ve metin

- **Alıcı:** E.164 biçiminde Türkiye cep numarası: `+905XXXXXXXXX`.
  Boşluklu yazım ve yurt dışı numaraları kabul edilmez.
- **Gönderici:** Hesabınıza kayıtlı gönderici adı; büyük/küçük harf fark etmez. Abone numarasıyla
  gönderim yalnızca bu izni olan hesaplarda yapılabilir.
- **Metin:** Her zaman tek SMS'tir. Baştaki ve sondaki boşluklar kırpılır, Türkçe karakterler Latin
  karşılığına çevrilir (`ğ` → `g`), desteklenmeyen karakterler (emoji vb.) `?` olur. Çevrilmiş metin
  gönderici adıyla en fazla 155, abone numarasıyla en fazla 160 karakter olabilir.

Paket bu kuralları kendisi denetlemez; denetimi Netgsm yapar ve uymayan istek `400 INVALID_REQUEST`
alır. Kurallar değiştiğinde paketi güncellemeniz gerekmez.

## Hata yönetimi

`send()` bir hata durumunda `Netgsm\Otp\OtpException` fırlatır:

| Alan | Açıklama |
|---|---|
| `errorCode` | Hata kodu. Kontrollerinizi HTTP durum koduna değil buna göre yazın. |
| `httpStatus` | HTTP durum kodu; bağlantı hatasında `0` |
| `requestId` | Netgsm'in istek kimliği. Destek talebinde paylaşın. |
| `violations` | Alan bazlı doğrulama hataları (`[['field' => ..., 'message' => ...]]`) |
| `mayHaveBeenSent` | `true` ise OTP gitmiş olabilir |
| `getMessage()` | Kod, ayrıntı ve `requestId`'yi içeren tek satır; loglamak için |

Ayar eksikse (abone numarası, şifre, gönderici yoksa) ya da alıcı veya metin boşsa
`InvalidArgumentException` fırlatılır. Bu durumda hiçbir istek gönderilmez.

```php
try {
    $jobId = $otp->send($phone, "Dogrulama kodunuz: $code");
} catch (OtpException $e) {
    error_log($e->getMessage());

    if ($e->mayHaveBeenSent) {
        // Otomatik olarak yeniden göndermeyin: kullanıcıya iki farklı kod ulaşabilir.
        // Kullanıcıya "kod gelmediyse yeniden isteyin" seçeneği sunun.
    }
}
```

Paketin kendisi şunları yapar:

- `401`: bir kez yeniden giriş yapar ve isteği tekrarlar.
- `503`: 1 ve 2 saniye bekleyerek iki kez tekrar dener. `503`'te hiçbir şey gönderilmemiştir.
- `429`: tekrar denemez. Hiçbir şey gönderilmemiştir.
- `500`: tekrar denemez. OTP gönderilmiş olabilir (`mayHaveBeenSent` `true`).

Sık karşılaşılan kodlar (küçük harfli olanlar token alınırken döner):

| HTTP | `errorCode` | Ne zaman | Ne yapmalı |
|---|---|---|---|
| 0 | `NETWORK_ERROR` | Bağlantı kurulamadı, zaman aşımı ya da TLS hatası | `mayHaveBeenSent`'e bakın. TLS hatası için bkz. [Sorun giderme](#sorun-giderme). |
| 400 | `INVALID_REQUEST` | Numara geçersiz, metin çok uzun ya da alan boş | `getMessage()` ve `violations`'a göre isteği düzeltin. |
| 401 | `invalid_credentials` | Abone numarası ya da şifre hatalı | Bilgileri düzeltin. Aynı bilgiyle tekrar denemeyin: dakikada 5 hatalı deneme `429 too_many_failed_attempts` getirir. |
| 403 | `ip_not_allowed` | Sunucunuzun IP adresine API izni yok | Portal: Abonelik İşlemleri → API İşlemleri → API Erişim İzinleri |
| 403 | `FORBIDDEN` | Hesapta hizmet açık değil, alt kullanıcının SMS yetkisi yok ya da `enterprise` hesabınıza uymuyor | `getMessage()` nedeni söyler. |
| 422 | `REJECTED_HEADER` | Gönderici adı hesabınızda kayıtlı değil ya da kullanılamıyor | Kayıtlı başlıklarınızı portalda kontrol edin. |
| 422 | `INSUFFICIENT_CREDIT` | OTP için kredi, paket ya da kampanya yok | Bakiye ya da paket tanımlayın. Hiçbir şey gönderilmedi. |
| 429 | `RATE_LIMITED` | OTP gönderim sınırı aşıldı | Hiçbir şey gönderilmedi; bir süre sonra tekrar deneyin. |
| 429 | `rate_limited` | IP başına dakikada 30 token isteği aşıldı | Token önbelleği verin. |
| 500 | `INTERNAL_ERROR` | Netgsm tarafında beklenmeyen hata | Otomatik tekrar yok; `requestId` ile bildirin. |
| 503 | `SERVICE_UNAVAILABLE` | İki tekrardan sonra servis hâlâ kullanılamıyor | Hiçbir şey gönderilmedi; daha sonra tekrar deneyin. |

Tam liste: [OTP API dokümanı](https://api-docs.netgsm.com.tr/sms/sending/send-otp/) ve
[token dokümanı](https://api-docs.netgsm.com.tr/auth/token/).

## Sandbox

Netgsm sandbox'ı canlı API ile aynı istekleri kabul eder ve aynı hataları döner, ama **SMS göndermez**
ve ücretsizdir. Yalnızca demo hesaplarla çalışır:

```php
$otp = new Otp(
    username: '<demo hesap>',
    password: '<demo şifre>',
    sender: 'TESTHEADER',
    baseUrl: 'https://ext-sandbox.netgsm.com.tr',
);
```

Demo hesaplar: [SMS API v3 sandbox](https://api-docs.netgsm.com.tr/sandbox/sms-api-v-3-sandbox/).

## Sorun giderme

### `NETWORK_ERROR SSL certificate problem: unable to get local issuer certificate`

3.0 sunucu sertifikasını doğrular (2.x doğrulamıyordu). PHP bir CA sertifika paketi bulamazsa
(çoğunlukla Windows, XAMPP, WAMP) bağlantı bu hatayla kesilir. Çözüm:

1. https://curl.se/ca/cacert.pem dosyasını indirin.
2. `php.ini`'ye ekleyin:

   ```ini
   curl.cainfo = "C:\php\extras\ssl\cacert.pem"
   openssl.cafile = "C:\php\extras\ssl\cacert.pem"
   ```

3. Web sunucusunu ya da PHP-FPM'i yeniden başlatın.

Sertifika doğrulamasını kapatmayın: kapalıyken araya giren biri şifrenizi ve token'ınızı okuyabilir.

### `Abone numarası yok: NETGSM_USERCODE tanımlayın...`

Ortam değişkeni PHP'ye ulaşmıyor. Laravel'de `config:cache` sonrası `.env` okunmaz; değerleri config
üzerinden verin (bkz. [Token önbelleği](#token-önbelleği)).

## Test

```bash
composer install
composer test
```

Testler yerel bir stub sunucuya karşı çalışır; Netgsm'e istek göndermez.

## Destek

- API dokümanı: https://api-docs.netgsm.com.tr/
- Bilgi Bankası (hesap, API erişimi, gönderici adı): https://bilgibankasi.netgsm.com.tr/
- Teknik destek: teknikdestek@netgsm.com.tr (hata bildirirken `requestId`'yi ve varsa `jobId`'yi
  ekleyin)

## Lisans

MIT. Bkz. [LICENSE](LICENSE).
