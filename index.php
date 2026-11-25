<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/guest_helpers.php';
require_once __DIR__ . '/config/invitation.php';

// Alamat dasar folder proyek (otomatis, jalan di Laragon maupun hosting).
// Dipakai pada <base> agar CSS, JS, gambar, dan API tetap terbaca
// ketika undangan dibuka lewat link pendek /i/KODE.
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = rtrim($basePath, '/') . '/';

// Default jika pengunjung membuka halaman tanpa token
$guestName = 'Tamu Undangan';
$guestError = false;
$isPersonalGuest = false;
$invitation = null;

$invitationSlug = strtolower(trim((string)($_GET['u'] ?? '')));
$shortCode = trim((string)($_GET['short'] ?? ''));
$guestToken = trim((string)($_GET['guest'] ?? ''));
$guestSlug = strtolower(trim((string)($_GET['tamu'] ?? '')));

try {

    ensure_invitation_schema($pdo);

    if ($shortCode !== '' || $guestToken !== '') {

        // Link lama: /i/KODE atau ?guest=TOKEN
        $guest = null;

        if ($shortCode !== '') {
            if (preg_match('/\A[A-Za-z0-9]{6,12}\z/', $shortCode)) {
                $stmt = $pdo->prepare(
                    "SELECT guest_name, invitation_id
                     FROM invitation_guests
                     WHERE short_code = :code
                     LIMIT 1"
                );
                $stmt->execute([':code' => $shortCode]);
                $guest = $stmt->fetch();
            }
        } elseif (preg_match('/\A[a-f0-9]{64}\z/i', $guestToken)) {
            $stmt = $pdo->prepare(
                "SELECT guest_name, invitation_id
                 FROM invitation_guests
                 WHERE token = :token
                 LIMIT 1"
            );
            $stmt->execute([':token' => $guestToken]);
            $guest = $stmt->fetch();
        }

        if ($guest) {
            $guestName = $guest['guest_name'];
            $isPersonalGuest = true;
            $invitation = invitation_by_id($pdo, (int)$guest['invitation_id']);
        }

        if (!$invitation) {
            $guestError = true;
        }

    } else {

        // Link /slug-undangan atau /slug-undangan/nama-tamu.
        // Tanpa slug: pakai undangan bawaan.
        $invitation = $invitationSlug !== ''
            ? invitation_by_slug($pdo, $invitationSlug)
            : invitation_default($pdo);

        if (!$invitation) {
            $guestError = true;
        } elseif ($guestSlug !== '') {

            if (!preg_match('/\A[a-z0-9-]{1,100}\z/', $guestSlug)) {
                $guestError = true;
            } else {
                // Nama cadangan dari link, dipakai bila nama tidak ada di database.
                $guestName = guest_name_from_slug($guestSlug);
                $isPersonalGuest = true;

                try {
                    $stmt = $pdo->prepare(
                        "SELECT guest_name
                         FROM invitation_guests
                         WHERE invitation_id = :inv AND slug = :slug
                         LIMIT 1"
                    );

                    $stmt->execute([
                        ':inv' => $invitation['id'],
                        ':slug' => $guestSlug
                    ]);

                    $guest = $stmt->fetch();

                    if ($guest) {
                        $guestName = $guest['guest_name'];
                    }
                } catch (PDOException $e) {
                    error_log('Guest slug lookup: ' . $e->getMessage());
                }
            }
        }
    }

} catch (PDOException $e) {

    error_log('Guest validation error: ' . $e->getMessage());

    http_response_code(500);
    exit('Terjadi kesalahan saat memuat undangan.');
}

// Slug undangan untuk JavaScript (RSVP, ucapan).
$invitationSlugOut = $invitation['slug'] ?? '';

$guestName = htmlspecialchars(
    $guestName,
    ENT_QUOTES,
    'UTF-8'
);

// Hentikan akses jika token tidak valid
if ($guestError) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Undangan Tidak Ditemukan</title>

        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: #f8f5f0;
                font-family: Georgia, serif;
                color: #4b4038;
                text-align: center;
            }

            .error-container {
                width: 100%;
                max-width: 440px;
                padding: 45px 30px;
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 10px 35px rgba(0,0,0,0.07);
            }

            .error-icon {
                font-size: 55px;
                margin-bottom: 20px;
            }

            h1 {
                font-size: 25px;
                margin-bottom: 15px;
                font-weight: normal;
            }

            p {
                font-family: Arial, sans-serif;
                font-size: 14px;
                line-height: 1.8;
                color: #777;
                margin-bottom: 25px;
            }

            .contact-text {
                font-size: 12px;
                color: #999;
                margin-bottom: 0;
            }
        </style>
    </head>

    <body>
        <div class="error-container">
            <div class="error-icon">✉️</div>

            <h1>Undangan Tidak Ditemukan</h1>

            <p>
                Maaf, tautan undangan yang Anda buka tidak valid
                atau sudah tidak tersedia.
            </p>

            <p class="contact-text">
                Silakan hubungi pihak pengundang untuk mendapatkan
                tautan undangan yang benar.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="id">
  <head>
    <meta charset="UTF-8" />

    <!-- Base URL agar aset tetap terbaca melalui URL pendek -->
    <base href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>" />
    <meta name="invitation" content="<?= htmlspecialchars($invitationSlugOut, ENT_QUOTES, 'UTF-8') ?>" />

    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    />
    <meta name="theme-color" content="#21684F" />
    <meta name="format-detection" content="telephone=no" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta
      name="apple-mobile-web-app-status-bar-style"
      content="black-translucent"
    />
    <title>The Wedding of Rifky &amp; Sinta</title>

    <!-- Tailwind CSS untuk tata letak responsif -->
    <!-- Tailwind CSS (file lokal, dibangun dari tailwind.config.js: npm run build:css) -->
    <link rel="stylesheet" href="css/tailwind.css" />

    <!-- Google Fonts: Cormorant Garamond & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap"
      rel="stylesheet"
    />

    <!-- Menghubungkan ke file CSS -->
    <link rel="stylesheet" href="css/style.css" />
    <link rel="stylesheet" href="css/theme-dark.css" />
  </head>
  <body class="antialiased">
    <!-- BAGIAN 1: VIDEO PEMBUKA & COVER -->
    <section id="intro-overlay">
      <video
        id="intro-video"
        autoplay
        muted
        playsinline
        webkit-playsinline
        preload="auto"
        disablepictureinpicture
        poster="assets/images/pic_1.jpeg"
      >
        <source src="assets/videos/hero1.mp4" type="video/mp4" />
        Browser Anda tidak mendukung pemutaran video HTML5.
      </video>

      <div class="video-backdrop-gradient"></div>

      <div id="intro-card" class="intro-card">
        <p class="text-xs tracking-[0.28em] uppercase text-[#D3E4DC] mb-2">
          THE WEDDING OF
        </p>

        <div class="my-3">
          <p
            class="text-xs tracking-[0.2em] uppercase text-[#C5A878] font-medium mb-1"
          >
            MR. &amp; MRS.
          </p>
          <h1
            class="font-serif-elegant text-4xl sm:text-5xl font-semibold leading-tight text-white"
          >
            Rifky
            <span class="italic font-normal text-[#C5A878]">&amp;</span> Sinta
          </h1>
        </div>

        <!-- <p class="text-sm text-[#E0ECE6] mb-6">3 &amp; 6 Desember 2026</p> -->

        <div class="py-3 px-4 mb-6">
          <p class="text-[11px] uppercase tracking-wider text-[#CFE0D7] mb-1">
            Kepada Yth. Bapak/Ibu/Saudara/i:
          </p>
          <h3
  id="guest-name"
  class="font-serif-elegant text-2xl font-semibold text-white"
  <?php if ($isPersonalGuest): ?>data-guest-name="<?= $guestName ?>"<?php endif; ?>
>
  <?= $guestName ?>
</h3>
        </div>

        <button
          id="open-invitation-btn"
          class="w-full py-3.5 px-6 rounded-full bg-[#F4F8F6] text-[#1E3F32] font-semibold text-sm tracking-wide hover:bg-white transition transform hover:scale-[1.02] shadow-lg cursor-pointer"
        >
          Buka Undangan
        </button>
      </div>
    </section>

    <!-- BAGIAN 2: KONTEN UTAMA UNDANGAN -->
    <main
      id="main-content"
      class="max-w-2xl mx-auto px-4 py-8 sm:py-12 space-y-8 hidden"
    >
      <!-- HERO SECTION -->
      <section class="elegant-card overflow-hidden scroll-animate fade-up">
        <div class="relative h-[min(460px,72svh)] sm:h-[540px] w-full">
          <img
            src="assets/images/pic_1.jpeg"
            alt="Rifky Muhamad Fauzi & Sinta Apriyani"
            class="w-full h-full object-cover object-top"
            onerror="
              this.src =
                'https://placehold.co/800x1000/1E3F32/FFFFFF?text=Foto+1+(pic_1.jpeg)'
            "
          />
          <div
            class="absolute inset-0 bg-gradient-to-t from-[#163026] via-[#163026]/30 to-transparent"
          ></div>

          <div
            class="absolute bottom-0 inset-x-0 p-7 sm:p-10 text-center text-white"
          >
            <p class="text-xs tracking-[0.28em] uppercase text-[#C5A878] mb-2">
            <b>Walimatul 'Ursy</b>
            </p>
            <h2
              class="font-serif-elegant text-4xl sm:text-5xl font-semibold mb-3"
            >
              Rifky &amp; Sinta
            </h2>
            <p
              class="text-xs sm:text-sm text-[#E2ECE7] italic max-w-md mx-auto leading-relaxed"
            >
              "Dan di antara tanda-tanda kekuasaan-Nya ialah Dia menciptakan
              untukmu pasangan hidup dari jenismu sendiri, supaya kamu merasa
              tenteram kepadanya."
              <span class="block not-italic font-semibold text-[#C5A878] mt-1"
                >— QS. Ar-Rum: 21</span
              >
            </p>
          </div>
        </div>
      </section>

      <!-- PROFIL MEMPELAI -->
      <section
        class="elegant-card p-7 sm:p-10 text-center scroll-animate fade-up"
      >
        <!-- <p
          class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
        >
          MEMPELAI BERBAHAGIA
        </p> -->
        <h2 class="font-serif-elegant text-2xl sm:text-3xl font-semibold text-[#1E3F32] mb-2">
  بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ
</h2>
        <p class="text-sm text-[#556860] max-w-md mx-auto mb-8">
          Dengan memohon rahmat dan ridho Allah SWT, kami bermaksud mengundang
          Bapak/Ibu/Saudara/i untuk menghadiri pernikahan kami:
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 items-center">
          <!-- Mempelai Pria -->
          <div class="scroll-animate fade-left">
            <div class="arch-frame w-48 h-64 mx-auto mb-4">
              <img
                src="assets/images/pic_2.jpeg"
                alt="Rifky Muhamad Fauzi"
                class="w-full h-full object-cover object-top"
                onerror="
                  this.src =
                    'https://placehold.co/400x550/2D5A48/FFFFFF?text=Foto+2+(pic_2.jpeg)'
                "
              />
            </div>
            <p
              class="text-xs uppercase tracking-widest text-[#C5A878] font-semibold"
            >
              The Groom
            </p>
            <h3
              class="font-serif-elegant text-2xl sm:text-3xl font-semibold text-[#1E3F32] mt-1"
            >
              Rifky Muhamad Fauzi
            </h3>
            <p class="text-xs text-[#556860] mt-1">
              Putra dari Bapak Sadun &amp; Ibu Elis Setiawati<br />
              (Anak ke-3 dari 5 bersaudara)
            </p>
          </div>

          <!-- Mempelai Wanita -->
          <div class="scroll-animate fade-right">
            <div class="arch-frame w-48 h-64 mx-auto mb-4">
              <img
                src="assets/images/pic_3.jpeg"
                alt="Sinta Apriyani"
                class="w-full h-full object-cover object-top"
                onerror="
                  this.src =
                    'https://placehold.co/400x550/2D5A48/FFFFFF?text=Foto+7+(pic_3.jpeg)'
                "
              />
            </div>
            <p
              class="text-xs uppercase tracking-widest text-[#C5A878] font-semibold"
            >
              The Bride
            </p>
            <h3
              class="font-serif-elegant text-2xl sm:text-3xl font-semibold text-[#1E3F32] mt-1"
            >
              Sinta Apriyani
            </h3>
            <p class="text-xs text-[#556860] mt-1">
              Putri dari Bapak Ade Diat &amp; Ibu Nenden Sumiati<br />
              (Anak ke-4 dari 4 bersaudara)
            </p>
          </div>
        </div>
      </section>

      <!-- TANGGAL, WAKTU & COUNTDOWN -->
      <section
        class="elegant-card p-7 sm:p-10 text-center scroll-animate fade-up"
      >
        <p
          class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
        >
          SAVE THE DATE
        </p>
        <h2
          class="font-serif-elegant text-3xl sm:text-4xl font-semibold text-[#1E3F32] mb-6"
        >
          Waktu &amp; Tempat Acara
        </h2>

        <div
          class="photo-frame h-64 sm:h-80 w-full mb-6 scroll-animate zoom-in"
        >
          <img
            src="assets/images/pic_4.jpeg"
            loading="lazy"
            decoding="async"
            alt="Prewedding Rifky Muhamad Fauzi & Sinta Apriyani"
            class="object-cover object-top"
            onerror="
              this.src =
                'https://placehold.co/800x500/1E3F32/FFFFFF?text=Foto+3+(pic_4.jpeg)'
            "
          />
        </div>

        <!-- Countdown Timer -->
        <div
          id="countdown"
          class="grid grid-cols-4 gap-2.5 max-w-md mx-auto mb-8"
        >
          <div class="bg-[#E4FDE1] rounded-2xl py-3.5 px-2">
            <span
              id="days"
              class="block font-serif-elegant text-2xl sm:text-3xl font-bold text-[#1E3F32]"
              >00</span
            >
            <small class="text-[11px] uppercase tracking-wider text-[#4A6B5D]"
              >Hari</small
            >
          </div>
          <div class="bg-[#E4FDE1] rounded-2xl py-3.5 px-2">
            <span
              id="hours"
              class="block font-serif-elegant text-2xl sm:text-3xl font-bold text-[#1E3F32]"
              >00</span
            >
            <small class="text-[11px] uppercase tracking-wider text-[#4A6B5D]"
              >Jam</small
            >
          </div>
          <div class="bg-[#E4FDE1] rounded-2xl py-3.5 px-2">
            <span
              id="minutes"
              class="block font-serif-elegant text-2xl sm:text-3xl font-bold text-[#1E3F32]"
              >00</span
            >
            <small class="text-[11px] uppercase tracking-wider text-[#4A6B5D]"
              >Menit</small
            >
          </div>
          <div class="bg-[#E4FDE1] rounded-2xl py-3.5 px-2">
            <span
              id="seconds"
              class="block font-serif-elegant text-2xl sm:text-3xl font-bold text-[#1E3F32]"
              >00</span
            >
            <small class="text-[11px] uppercase tracking-wider text-[#4A6B5D]"
              >Detik</small
            >
          </div>
        </div>

        <!-- Kartu Akad & Resepsi -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-left mb-6">
          <div
            class="bg-[#EGEFEG] border border-[#D3E0D9] rounded-2xl p-5 scroll-animate fade-left"
          >
            <!-- <span
              class="inline-block text-xs font-semibold text-[#C5A878] uppercase tracking-wider mb-1"
              >01. Prosesi</span
            >-->
            <h3
              class="font-serif-elegant text-2xl font-semibold text-[#1E3F32] mb-2"
            >
              Akad Nikah
            </h3>
            <p class="text-sm font-medium text-[#1A2923]">
              Kamis, 3 Desember 2026
            </p>
            <p class="text-xs text-[#4A6B5D] mb-3">Pukul 09.00 WIB – selesai</p>
            <p class="text-xs text-[#556860] leading-relaxed mb-3">
              <strong>KUA Baleendah</strong><br />
              Kec. Baleendah, Kab. Bandung
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a
            href="https://maps.app.goo.gl/DajVm7JQZRFQywGS6?g_st=iw"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-block border border-[#1E3F32] text-[#1E3F32] hover:bg-[#1E3F32] hover:text-white text-xs sm:text-sm font-medium px-7 py-3 rounded-full transition"
          >
            Lokasi Akad (KUA Baleendah)
          </a>
            </div>
          </div>

          <div
            class="bg-[#EGEFEG] border border-[#D3E0D9] rounded-2xl p-5 scroll-animate fade-right"
          >
            <!-- <span
              class="inline-block text-xs font-semibold text-[#C5A878] uppercase tracking-wider mb-1"
              >02. Perayaan</span 
            >-->
            <h3
              class="font-serif-elegant text-2xl font-semibold text-[#1E3F32] mb-2"
            >
              Resepsi Pernikahan
            </h3>
            <p class="text-sm font-medium text-[#1A2923]">
              Minggu, 6 Desember 2026
            </p>
            <p class="text-xs text-[#4A6B5D] mb-3">Pukul 11.00 WIB – selesai</p>
            <p class="text-xs text-[#556860] leading-relaxed mb-3">
              <strong>Kediaman Mempelai Wanita</strong><br />
              Kp. Kebon Seureuh Cimuncang RT 09/RW 12, Manggahang, Kec.
              Baleendah, Kab. Bandung
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
              <a
                href="https://www.google.com/maps/search/?api=1&amp;query=XJGX%2B5Q2%2C+Manggahang%2C+Kec.+Baleendah%2C+Kabupaten+Bandung"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-block border border-[#1E3F32] text-[#1E3F32] hover:bg-[#1E3F32] hover:text-white text-xs sm:text-sm font-medium px-7 py-3 rounded-full transition"
              >
                Lokasi Resepsi
              </a>
            </div>
          </div>
        </div>
      </section>

      <!-- GALERI FOTO -->
      <section
        class="elegant-card p-7 sm:p-10 text-center scroll-animate fade-up"
      >
        <p
          class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
        >
          OUR MOMENTS
        </p>
        <h2
          class="font-serif-elegant text-3xl sm:text-4xl font-semibold text-[#1E3F32] mb-2"
        >
          Galeri Kebahagiaan
        </h2>
        <p class="text-xs sm:text-sm text-[#556860] mb-7">
          Ketuk foto untuk memperbesar tampilan
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
        
          <div
            class="photo-frame h-56 sm:h-64 col-span-2 sm:col-span-1 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_6.jpeg')"
          >
            <img
              src="assets/images/pic_6.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 8"
              onerror="
                this.src =
                  'https://placehold.co/400x550/1E3F32/FFFFFF?text=pic_6.jpeg'
              "
            />
          </div>
          <div
            class="photo-frame h-56 sm:h-64 col-span-2 sm:col-span-1 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_11.jpeg')"
          >
            <img
              src="assets/images/pic_11.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 8"
              onerror="
                this.src =
                  'https://placehold.co/400x550/1E3F32/FFFFFF?text=pic_11.jpeg'
              "
            />
          </div>

          <div
            class="photo-frame h-56 sm:h-64 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_7.jpeg')"
          >
            <img
              src="assets/images/pic_7.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 1"
              onerror="
                this.src =
                  'https://placehold.co/400x550/1E3F32/FFFFFF?text=pic_7.jpeg'
              "
            />
          </div>
          <div
            class="photo-frame h-56 sm:h-64 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_8.jpeg')"
          >
            <img
              src="assets/images/pic_8.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 2"
              onerror="
                this.src =
                  'https://placehold.co/400x550/1E3F32/FFFFFF?text=pic_8.jpeg'
              "
            />
          </div>
           <div
            class="photo-frame h-56 sm:h-64 sm:col-span-2 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_10.jpeg')"
          >
            <img
              src="assets/images/pic_10.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 4"
              class="object-center"
              onerror="
                this.src =
                  'https://placehold.co/800x550/1E3F32/FFFFFF?text=pic_10.jpeg'
              "
            />
          </div>
          <div
            class="photo-frame h-56 sm:h-64 scroll-animate zoom-in"
            onclick="openLightbox('assets/images/pic_5.jpeg')"
          >
            <img
              src="assets/images/pic_5.jpeg"
              loading="lazy"
              decoding="async"
              alt="Momen 3"
              onerror="
                this.src =
                  'https://placehold.co/400x550/1E3F32/FFFFFF?text=pic_5.jpeg'
              "
            />
          </div>
          
          
        </div>
      </section>

      <!-- TANDA KASIH / AMPLOP DIGITAL -->
      <section
        class="elegant-card p-7 sm:p-10 text-center scroll-animate fade-up"
      >
        <p
          class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
        >
          WEDDING GIFT
        </p>
        <h2
          class="font-serif-elegant text-3xl sm:text-4xl font-semibold text-[#1E3F32] mb-2"
        >
          Tanda Kasih
        </h2>
        <p class="text-xs sm:text-sm text-[#556860] max-w-md mx-auto mb-7">
          Doa restu Anda merupakan karunia yang sangat berarti bagi kami. Namun
          jika memberi adalah ungkapan tanda kasih, Anda dapat menyalurkannya
          melalui rekening berikut:
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg mx-auto">
          <!-- Rekening Mempelai Pria -->
          <div
            class="bg-[#F7FAF8] border border-[#D3E0D9] rounded-2xl p-5 flex flex-col justify-between scroll-animate fade-left"
          >
            <div>
              <span
                class="inline-block text-[11px] font-semibold uppercase tracking-wider text-[#C5A878] mb-1"
                >Mempelai Pria</span
              >
              <h3
                class="font-serif-elegant text-2xl font-bold text-[#1E3F32] mb-2"
              >
                Bank BCA
              </h3>
              <p
                id="rek-bca"
                class="text-base sm:text-lg font-semibold tracking-wider text-[#1A2923] mb-1"
              >
                8470531097
              </p>
              <p class="text-xs text-[#556860] mb-4">a.n. Rifky Muhamad Fauzi</p>
            </div>
            <button
              type="button"
              onclick="copyRekening('rek-bca', this)"
              class="w-full py-2.5 px-4 rounded-xl border border-[#1E3F32] text-[#1E3F32] hover:bg-[#1E3F32] hover:text-white text-xs font-medium transition cursor-pointer"
            >
              Salin No. Rekening
            </button>
          </div>

          <!-- Rekening Mempelai Pria (BRI) -->
          <div
            class="bg-[#F7FAF8] border border-[#D3E0D9] rounded-2xl p-5 flex flex-col justify-between scroll-animate fade-right"
          >
            <div>
              <span
                class="inline-block text-[11px] font-semibold uppercase tracking-wider text-[#C5A878] mb-1"
                >Mempelai Pria</span
              >
              <h3
                class="font-serif-elegant text-2xl font-bold text-[#1E3F32] mb-2"
              >
                Bank BRI
              </h3>
              <p
                id="rek-bri"
                class="text-base sm:text-lg font-semibold tracking-wider text-[#1A2923] mb-1"
              >
                038401064012502
              </p>
              <p class="text-xs text-[#556860] mb-4">a.n. Rifky Muhamad Fauzi</p>
            </div>
            <button
              type="button"
              onclick="copyRekening('rek-bri', this)"
              class="w-full py-2.5 px-4 rounded-xl border border-[#1E3F32] text-[#1E3F32] hover:bg-[#1E3F32] hover:text-white text-xs font-medium transition cursor-pointer"
            >
              Salin No. Rekening
            </button>
          </div>

          <!-- DANA Mempelai Wanita -->
          <div
            class="bg-[#F7FAF8] border border-[#D3E0D9] rounded-2xl p-5 flex flex-col justify-between sm:col-span-2 sm:w-[calc(50%-0.5rem)] sm:mx-auto scroll-animate fade-up"
          >
            <div>
              <span
                class="inline-block text-[11px] font-semibold uppercase tracking-wider text-[#C5A878] mb-1"
                >Mempelai Wanita</span
              >
              <h3
                class="font-serif-elegant text-2xl font-bold text-[#1E3F32] mb-2"
              >
                DANA
              </h3>
              <p
                id="rek-dana"
                class="text-base sm:text-lg font-semibold tracking-wider text-[#1A2923] mb-1"
              >
                087890430196
              </p>
              <p class="text-xs text-[#556860] mb-4">a.n. Sinta Apriyani</p>
            </div>
            <button
              type="button"
              onclick="copyRekening('rek-dana', this)"
              class="w-full py-2.5 px-4 rounded-xl border border-[#1E3F32] text-[#1E3F32] hover:bg-[#1E3F32] hover:text-white text-xs font-medium transition cursor-pointer"
            >
              Salin Nomor DANA
            </button>
          </div>
        </div>
      </section>

      <!-- BAGIAN RSVP -->
      <section class="elegant-card p-7 sm:p-10 scroll-animate fade-up">
        <div class="text-center mb-6">
          <p
            class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
          >
            KONFIRMASI KEHADIRAN
          </p>
          <h2
            class="font-serif-elegant text-3xl sm:text-4xl font-semibold text-[#1E3F32] mb-2"
          >
            RSVP
          </h2>
          <p class="text-xs sm:text-sm text-[#556860] max-w-md mx-auto">
            Merupakan suatu kehormatan bagi kami apabila Bapak/Ibu/Saudara/i
            berkenan hadir untuk memberikan doa restu.
          </p>
        </div>

        <form id="rsvp-form" class="max-w-md mx-auto space-y-4">
          <div>
            <label
              for="rsvp-name"
              class="block text-xs font-medium text-[#1E3F32] mb-1.5"
            >
              Nama Lengkap
            </label>
            <input
              type="text"
              id="rsvp-name"
              autocomplete="name"
              enterkeyhint="send"
              placeholder="Masukkan nama Anda"
              maxlength="100"
              required
              class="w-full px-4 py-3 rounded-xl border border-[#D3E0D9] bg-[#F9FCFA] text-sm focus:outline-none focus:border-[#1E3F32]"
            />
          </div>

          <div>
            <label
              for="rsvp-status"
              class="block text-xs font-medium text-[#1E3F32] mb-1.5"
            >
              Kehadiran
            </label>
            <select
              id="rsvp-status"
              required
              class="w-full px-4 py-3 rounded-xl border border-[#D3E0D9] bg-[#F9FCFA] text-sm focus:outline-none focus:border-[#1E3F32]"
            >
              <option value="Hadir">Ya, Saya Akan Hadir</option>
              <option value="Tidak Hadir">Maaf, Belum Bisa Hadir</option>
            </select>
          </div>

          <button
            type="submit"
            class="btn-bottle w-full py-3.5 text-sm font-medium cursor-pointer"
          >
            Kirim Konfirmasi Kehadiran
          </button>
        </form>

        <div
          id="rsvp-feedback"
          class="hidden max-w-md mx-auto mt-4 p-3.5 rounded-xl bg-[#E4EDE8] text-[#1E3F32] text-xs sm:text-sm text-center font-medium"
        ></div>
      </section>

      <!-- TEMPAT UCAPAN TAMU -->
      <section class="elegant-card p-7 sm:p-10 scroll-animate fade-up">
        <div class="text-center mb-6">
          <p
            class="text-xs font-semibold tracking-[0.25em] uppercase text-[#4A6B5D] mb-2"
          >
            DOA &amp; HARAPAN
          </p>
          <h2
            class="font-serif-elegant text-3xl sm:text-4xl font-semibold text-[#1E3F32] mb-2"
          >
            Ucapan Tamu
          </h2>
          <p class="text-xs sm:text-sm text-[#556860]">
            Tuliskan doa dan ucapan selamat untuk Rifky &amp; Sinta.
          </p>
        </div>

        <form id="wish-form" class="max-w-md mx-auto space-y-4 mb-8">
          <div>
            <label
              for="wish-name"
              class="block text-xs font-medium text-[#1E3F32] mb-1.5"
              >Nama Anda</label
            >
            <input
              type="text"
              id="wish-name"
              autocomplete="name"
              enterkeyhint="next"
              placeholder="Contoh: Keluarga Besar Bandung"
              maxlength="100"
              required
              class="w-full px-4 py-3 rounded-xl border border-[#D3E0D9] bg-[#F9FCFA] text-sm focus:outline-none focus:border-[#1E3F32]"
            />
          </div>

          <div>
            <label
              for="wish-message"
              class="block text-xs font-medium text-[#1E3F32] mb-1.5"
              >Ucapan &amp; Doa</label
            >
            <textarea
              id="wish-message"
              rows="3"
              maxlength="1000"
              placeholder="Tuliskan ucapan terbaik Anda di sini..."
              required
              class="w-full px-4 py-3 rounded-xl border border-[#D3E0D9] bg-[#F9FCFA] text-sm focus:outline-none focus:border-[#1E3F32]"
            ></textarea>
          </div>

          <button
            type="submit"
            class="btn-bottle w-full py-3.5 text-sm font-medium cursor-pointer"
          >
            Kirim Ucapan
          </button>
        </form>

        <!-- Daftar Ucapan -->
        <div
          id="wishes-list"
          class="max-w-md mx-auto max-h-80 overflow-y-auto custom-scroll space-y-3 pr-1"
        >
          <!-- Ucapan otomatis dirender oleh js/script.js -->
        </div>
      </section>

      <!-- PENUTUP -->
      <footer
        class="elegant-card overflow-hidden text-center scroll-animate fade-up"
      >
        <div class="relative h-64 sm:h-72 w-full">
          <img
            src="assets/images/pic_11.jpeg"
            loading="lazy"
            decoding="async"
            alt="Terima Kasih"
            class="w-full h-full object-cover object-top"
            onerror="
              this.src =
                'https://placehold.co/800x450/1E3F32/FFFFFF?text=Foto+8+(pic_11.jpeg)'
            "
          />
          <div
            class="absolute inset-0 bg-[#163026]/70 flex flex-col items-center justify-center p-6 text-white"
          >
            <p class="text-xs tracking-[0.2em] uppercase text-[#CFE0D7] mb-2">
              TERIMA KASIH ATAS DOA &amp; RESTU ANDA
            </p>
            <h4 class="font-serif-elegant text-3xl sm:text-4xl font-semibold">
              Rifky &amp; Sinta
            </h4>
          </div>
        </div>
      </footer>

      <!-- KREDIT PEMBUAT -->
      <div class="text-center scroll-animate fade-up">
        <p class="text-xs text-[#556860]">
          Created by
          <span class="font-semibold text-[#1E3F32]">Bleuzey.Project</span>
        </p>
      </div>
    </main>

    <!-- LIGHTBOX MODAL -->
    <div
      id="lightbox-modal"
      class="fixed inset-0 z-50 bg-black/85 hidden items-center justify-center p-4"
      onclick="closeLightbox()"
    >
      <div class="relative max-w-lg w-full" onclick="event.stopPropagation()">
        <img
          id="lightbox-img"
          src=""
          alt="Preview Foto"
          class="w-full max-h-[85vh] object-contain rounded-2xl shadow-2xl"
        />
        <button
          type="button"
          onclick="closeLightbox()"
          class="mt-3 mx-auto block px-5 py-2 bg-white/20 text-white text-xs rounded-full hover:bg-white/30"
        >
          Tutup Preview
        </button>
      </div>
    </div>
    <!-- YouTube Background Music -->
    <div
      id="youtube-player-container"
      style="
        position: fixed;
        width: 1px;
        height: 1px;
        overflow: hidden;
        opacity: 0;
        pointer-events: none;
      "
    >
      <div id="youtube-player"></div>
    </div>

    <!-- Tombol Kontrol Musik -->
    <button
      id="music-toggle"
      type="button"
      aria-label="Putar musik"
      title="Putar musik"
      class="hidden fixed bottom-6 right-6 z-[9998] w-12 h-12 rounded-full bg-[#1E3F32] text-white shadow-lg border border-[#C5A878] flex items-center justify-center hover:scale-110 transition-transform"
    >
      <span id="music-icon">♫</span>
    </button>

    <script src="https://www.youtube.com/iframe_api"></script>

    <!-- JavaScript -->
    <script src="js/script.js"></script>
  </body>
</html>
