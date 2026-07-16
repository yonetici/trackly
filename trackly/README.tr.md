# Trackly 📊✨

[![WordPress Sürümü](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP Sürümü](https://img.shields.io/badge/PHP-7.4%2B-8892bf.svg)](https://php.net)
[![Lisans](https://img.shields.io/badge/Lisans-GPLv2%2B-red.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Çeviri Durumu](https://img.shields.io/badge/%C3%87eviriler-T%C3%BCrk%C3%A7e%20(Haz%C4%B1r)-brightgreen.svg)](#languages)

> **Trackly**; WordPress için yüksek performanslı, GDPR uyumlu bir Google Analytics 4 (GA4) gösterge paneli, duyarlı (responsive) tıklama ısı haritası istemcisi ve görsel özel etkinlik (event) sihirbazıdır. Gelişmiş analizleri, kullanıcı davranışı akışlarını ve yapay zeka destekli önerileri doğrudan WordPress yönetim panelinize getirir.

🌐 **[English Version (İngilizce Versiyon)](README.md)**

---

## 📌 İçindekiler
1. [Temel Özellikler](#-temel-özellikler)
2. [Mimari ve Dizin Yapısı](#-mimari-ve-dizin-yapısı)
3. [Kurumsal Güvenlik ve Kriptografi](#-kurumsal-güvenlik-ve-kriptografi)
4. [Performans ve Varlık Optimizasyonu](#-performans-ve-varlık-optimizasyonu)
5. [Veritabanı Şeması ve Oturum Tabanlı Örnekleme](#-veritabanı-şeması-ve-oturum-tabanlı-örnekleme)
6. [Detaylı Kurulum Kılavuzu (Google Cloud Console)](#-detaylı-kurulum-kılavuzu-google-cloud-console)
7. [Uluslararasılaştırma ve Çeviri (i18n)](#-uluslararasılaştırma-ve-çeviri-i18n)
8. [Lisans](#-lisans)

---

## 🚀 Temel Özellikler

*   **Premium GA4 Analitik Gösterge Paneli:** Sayfa görüntüleme, tekil ziyaretçi, hemen çıkma oranları ve ortalama oturum süresi gibi metrikleri ApexCharts grafik entegrasyonuyla etkileşimli bir şekilde görüntüleyin.
*   **Aktif Trafik ve Cihaz Kategorisi Metrikleri:** Trafiği yönlendirme kanallarına (Organik, Doğrudan, Referans, Sosyal) ve cihazlara (Masaüstü, Mobil, Tablet) göre segmentlere ayırın.
*   **Ön Yüz Admin İstatistik Barı:** Giriş yapmış yöneticiler için doğrudan sitenin ön yüzünde beliren, sayfa düzeyinde performans metriklerini gösteren cam tasarımlı (glassmorphic) bar.
*   **Duyarlı Yerel Tıklama Isı Haritaları:** Ziyaretçilerin ekran genişliği ve yüksekliğine göre oranlanmış ($X\%$ ve $Y\%$) tıklama noktalarını görselleştirin. Tüm ekran boyutlarında doğru çalışır.
*   **Görsel GA4 Etkinlik Sihirbazı:** Koda dokunmadan, sayfa üzerindeki butonları veya linkleri tıklayarak özel GA4 takip etkinlikleri oluşturun.
*   **Yapay Zeka Destekli İçgörüler:** Sayfa istatistiklerini (hemen çıkma oranları, kalma süresi, sayfa görüntülemeleri) otomatik olarak değerlendirerek dönüşüm oranlarını artıracak pratik öneriler sunar.

---

## 📂 Mimari ve Dizin Yapısı

Trackly, modern WordPress eklenti tasarım ilkeleri doğrultusunda, bir sınıf otomatik yükleyicisi (autoloader) kullanarak ve ağır bileşenleri yalnızca gerektiğinde yükleyerek tasarlanmıştır.

```text
trackly/
├── trackly.php                 # Ana giriş noktası (Autoloader, kancaların tetiklenmesi)
├── uninstall.php               # Kaldırma şablonu (Ayarlar, geçici veriler, DB tablolarının temizlenmesi)
├── admin/                      # Yönetici paneli bileşenleri
│   ├── class-trackly-admin.php # Kontrol paneli yönetimi, REST API geri çağırma uç noktaları
│   ├── css/
│   │   └── trackly-admin.css   # Yönetici Paneli için Outfit fontlu premium stiller
│   └── js/
│       ├── trackly-admin.js    # Grafik paneli, ApexCharts entegrasyonu
│       └── vendor/
│           └── apexcharts.min.js # Yerelleştirilmiş grafik kütüphanesi
├── includes/                   # Çekirdek iş mantığı katmanı
│   ├── class-trackly.php       # Eklenti yükleyici sınıf (alt modülleri başlatır)
│   ├── class-trackly-api.php   # Google OAuth 2.0 JWT motoru ve GA4 API istemcisi
│   └── class-trackly-db.php    # Veritabanı şeması oluşturma ve ham tıklama kaydı
├── public/                     # Ön yüz takip ve yönetim bileşenleri
│   ├── class-trackly-public.php # Ön yüz kancaları, yönetim paneli arayüzü enjektörü
│   ├── css/
│   │   └── trackly-public.css  # Ön yüz paneli cam tasarımı (glassmorphism) stilleri
│   └── js/
│       ├── trackly-public.js   # Ön yüz yönetim paneli arayüz mantığı, ısı haritası noktaları
│       └── trackly-tracker.js  # GDPR uyumlu, hafif 5KB tıklama takipçisi
└── languages/                  # i18n Çeviri dosyaları (.po, .mo şablonları)
    ├── trackly-tr_TR.po        # Türkçe çeviri kaynak şablonu
    └── trackly-tr_TR.mo        # Derlenmiş Türkçe yerelleştirme ikili dosyası
```

### Modül Sorumlulukları

| Sınıf / Dosya | Sorumluluk | Yüklenme Aşaması |
| :--- | :--- | :--- |
| `Trackly` | Ön yüz/yönetici katmanlarını ve veritabanlarını başlatır | `plugins_loaded` sırasında yüklenir |
| `Trackly_DB` | Veritabanı şeması geçişleri ve tıklama kaydetme işleyicileri | Eklenti aktivasyonunda / temizlik cron'unda çalışır |
| `Trackly_API` | JWT oluşturma, OAuth önbelleğe alma ve GA4 batch sorgularını yönetir | İhtiyaç anında (on-demand) yüklenir |
| `Trackly_Admin` | Yönetici alt menülerini, ayar şemalarını ve REST API'yi kaydeder | `is_admin()` durumunda yüklenir |
| `Trackly_Public` | Takip kodlarını sunar ve yönetici ön yüz panelini enjekte eder | Sitenin ön yüzünde yüklenir |

---

## 🔒 Kurumsal Güvenlik ve Kriptografi

Trackly, Google Analytics kimlik bilgilerinizi güvenlik en iyi uygulamalarını kullanarak korur:

1.  **AES-256-CBC Gizli Şifreleme:** Google Hizmet Hesabı kimlik bilgileriniz veritabanında saklanmadan önce şifrelenir.
2.  **Dinamik Salt Oluşturma:** Sunucunuzun güvenlik anahtarlarını (`SECURE_AUTH_KEY`, `NONCE_KEY`), aktivasyon sırasında oluşturulan dinamik 64 karakterli benzersiz bir anahtarla (`trackly_secure_salt`) birleştirerek karmaşık bir anahtar oluşturur.
3.  **Sınırlandırılmış REST API İstek Limiti:** Tıklama kaydetme uç noktası, IP adresi başına dakikada maksimum 10 istek alacak şekilde sınırlandırılmıştır. Bu, veritabanının şişirilmesini ve DDoS girişimlerini engeller.
4.  **XSS ve Payload Koruması:** Etkinlik Sihirbazı üzerinden oluşturulan özel etkinlikler, CSS seçici alanlarında HTML/script enjeksiyonunu önlemek amacıyla katı bir regex denetiminden geçer.

---

## ⚡ Performans ve Varlık Optimizasyonu

*   **Koşullu Yükleme:** Ağır olan yönetim paneli JS ve CSS dosyaları **yalnızca** giriş yapmış yöneticiler için yüklenir.
*   **Sıfır Etki Takipçisi:** Standart ziyaretçiler yalnızca 5KB'ın altında, jQuery veya harici bağımlılığı olmayan hafif bir vanilla JavaScript takipçisi (`trackly-tracker.js`) indirir.
*   **Kısaltılmış Geçici Veriler:** API istekleri ve OAuth jetonları, API kotalarını aşmamak ve yükleme sürelerini hızlandırmak için transients (geçici veritabanı önbelleği) ile saklanır.

---

## 💾 Veritabanı Şeması ve Oturum Tabanlı Örnekleme

Trackly, tıklama koordinatlarını yerel olarak `wp_trackly_clicks` adlı özel bir veritabanı tablosunda saklar.

### Veritabanı Şeması

```sql
CREATE TABLE wp_trackly_clicks (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    page_url varchar(255) NOT NULL,
    element_tag varchar(50) NOT NULL,
    element_selector varchar(255) NOT NULL,
    click_x_pct float NOT NULL, -- ekran genişliğine oranlanmış X yüzdesi
    click_y_pct float NOT NULL, -- ekran yüksekliğine oranlanmış Y yüzdesi
    created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    KEY page_url (page_url(191))
);
```

### Örnekleme ve Otomatik Temizlik

*   **Örnekleme Oranı Seçeneği:** **Trackly > Ayarlar** bölümünden `%100`, `%50`, `%25` veya `%10` takip oranlarından birini seçin. Düşük oranlar, yüksek trafikli sitelerde veritabanının şişmesini engeller.
*   **Otomatik Temizlik Cron Görevi:** Günlük çalışan bir cron görevi (`trackly_daily_cleanup`), 30 günden eski tıklama verilerini otomatik olarak veritabanından temizler.

---

## ⚙️ Detaylı Kurulum Kılavuzu (Google Cloud Console)

Trackly'yi Google Analytics 4 (GA4) mülkünüze bağlamak için aşağıdaki adımları izleyin:

### Adım 1: Google Hizmet Hesabı (Service Account) Oluşturma
1.  [Google Cloud Console](https://console.cloud.google.com/) adresine gidin.
2.  Yeni bir proje oluşturun veya mevcut bir projeyi seçin.
3.  **API'ler ve Hizmetler > Kitaplık** bölümüne gidin.
4.  **Google Analytics Data API**'yi arayın ve **Etkinleştir**'e tıklayın.
5.  **IAM ve Yönetici > Hizmet Hesapları** bölümüne gidin.
6.  **Hizmet Hesabı Oluştur**'a tıklayın, bilgileri doldurun ve **Tamamlandı**'ya tıklayın.

### Adım 2: JSON Anahtarı Oluşturma
1.  Listeden yeni oluşturduğunuz Hizmet Hesabını seçin.
2.  **Anahtarlar (Keys)** sekmesine gidin.
3.  **Anahtar Ekle > Yeni Anahtar Oluştur** adımlarını izleyin.
4.  **JSON** biçimini seçin ve **Oluştur**'a tıklayın.
5.  Bilgisayarınıza bir JSON anahtar dosyası indirilecektir. Bu dosyayı güvenli bir yerde saklayın.

### Adım 3: GA4 Yetkilendirmesi
1.  İndirilen JSON dosyasındaki `client_email` değerini kopyalayın (Örn: `hizmet-hesabim@projem.iam.gserviceaccount.com`).
2.  **Google Analytics 4** yönetim panelinize gidin.
3.  **Yönetici > Mülk Erişim Yönetimi** bölümüne gidin.
4.  Mavi "+" butonuna tıklayarak yeni kullanıcı ekleme alanını açın.
5.  Kopyaladığınız hizmet hesabı e-postasını yapıştırın ve **Okuyucu (Viewer)** rolünü tanımlayın.

### Adım 4: Trackly Ayarları
1.  Google Analytics Mülk Ayarları bölümünden **GA4 Property ID**'nizi kopyalayın.
2.  **Trackly > Ayarlar** altındaki **GA4 Property ID** alanına yapıştırın.
3.  İndirilen **JSON anahtar dosyasının** tüm içeriğini **Hizmet Hesabı JSON Anahtarı** alanına yapıştırın.
4.  **Ayarları Kaydet** butonuna tıklayın.
5.  Google Analytics'ten canlı verileri çekmeye başlamak için **Demo Modu** seçeneğini devre dışı bırakın.

---

## 🌐 Uluslararasılaştırma ve Çeviri (i18n)

Trackly çevirilere tamamen hazırdır. Örnek olarak Türkçe çeviri şablonlarını içermektedir.

### Loco Translate ile Yerelleştirme
1.  WordPress sitenize **Loco Translate** eklentisini kurun.
2.  **Loco Translate > Eklentiler > Trackly** yolunu izleyin.
3.  **Yeni Dil** butonuna tıklayın.
4.  Dilinizi seçin ve **Çeviriye başla** butonuna tıklayın.
5.  Eklenti, `languages` klasöründe `.po` ve `.mo` dosyalarını otomatik oluşturup derleyecektir.

### Poedit ile Yerelleştirme
1.  [trackly-tr_TR.po](languages/trackly-tr_TR.po) şablon dosyasını **Poedit** ile açın.
2.  Metinleri kendi dilinize çevirin.
3.  Dosyayı `trackly-[locale].po` adıyla kaydedin (Örn: `trackly-fr_FR.po`).
4.  Poedit otomatik olarak bir `trackly-[locale].mo` ikili dosyası oluşturacaktır. Her iki dosyayı da eklenti dizinindeki `languages/` klasörüne yerleştirin.

---

## 📄 Lisans

GPLv2 veya üzeri. Dosya başlıklarında belirtilen lisans koşulları geçerlidir.
