
document.addEventListener("DOMContentLoaded", () => {
    // ==========================================
    // 1. PARAMETER NAMA TAMU
    // ==========================================

    const urlParams = new URLSearchParams(window.location.search);
    // Nama tamu: dari ?to=... atau dari link pendek /i/KODE (data-guest-name dari index.php)
    const guestParam =
        urlParams.get("to") ||
        document.getElementById("guest-name")?.dataset.guestName ||
        "";

    // Slug undangan (dari <meta name="invitation">), dikirim bersama RSVP dan ucapan.
    const invitationSlug =
        document.querySelector('meta[name="invitation"]')?.content || "";

    const guestName = document.getElementById("guest-name");
    const rsvpName = document.getElementById("rsvp-name");
    const wishName = document.getElementById("wish-name");

    if (guestParam) {
        const safeGuestName = guestParam.trim().slice(0, 100);

        if (safeGuestName) {
            guestName.textContent = safeGuestName;
            rsvpName.value = safeGuestName;
            wishName.value = safeGuestName;
        }
    }

    // ==========================================
    // 2. VIDEO PEMBUKA DAN INTRO
    // ==========================================

    const introVideo = document.getElementById("intro-video");
    const introCard = document.getElementById("intro-card");
    const introOverlay = document.getElementById("intro-overlay");
    const mainContent = document.getElementById("main-content");
    const openBtn = document.getElementById("open-invitation-btn");

    let introRevealed = false;

    function revealNamesCard() {
        if (introRevealed) return;

        introRevealed = true;
        introCard.classList.add("show-names");
    }

    const introTimer = setTimeout(revealNamesCard, 2000);

    introVideo.addEventListener("ended", () => {
        clearTimeout(introTimer);
        revealNamesCard();
    });

    introVideo.addEventListener("error", () => {
        clearTimeout(introTimer);
        revealNamesCard();
    });


    // ==========================================
    // 3. BUKA UNDANGAN DAN ANIMASI SCROLL
    // ==========================================

    let scrollObserver = null;

    function initScrollAnimations() {
        const animatedElements = document.querySelectorAll(".scroll-animate");

        if (!("IntersectionObserver" in window)) {
            animatedElements.forEach((el) => {
                el.classList.add("in-view");
            });
            return;
        }

        if (scrollObserver) {
            scrollObserver.disconnect();
        }

        scrollObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("in-view");
                        scrollObserver.unobserve(entry.target);
                    }
                });
            },
            {
                root: null,
                rootMargin: "0px 0px -60px 0px",
                threshold: 0.12
            }
        );

        animatedElements.forEach((el) => {
            scrollObserver.observe(el);
        });
    }

    openBtn.addEventListener("click", () => {
    introOverlay.classList.add("opened");

    mainContent.classList.remove("hidden");

    initScrollAnimations();

    document.body.style.overflow = "";

    // Mulai musik latar
    musicToggle.classList.remove("hidden");

    if (youtubeReady && youtubePlayer) {
        startBackgroundMusic();
    } else {
        musicStarted = true;
        pendingMusicStart = true;
    }
});

    // ==========================================
    // 4. COUNTDOWN TIMER
    // ==========================================

    // Hitung mundur ke 6 Desember 2026 (Resepsi), pukul 11.00 WIB
    const targetDate = new Date(
        "2026-12-06T11:00:00+07:00"
    ).getTime();

    const countdown = document.getElementById("countdown");

    function updateCountdown() {
        const now = Date.now();
        const distance = targetDate - now;

        if (distance <= 0) {
            if (!countdown.dataset.finished) {
                countdown.dataset.finished = "true";
                countdown.replaceChildren();

                const message = document.createElement("p");

                message.className =
                    "col-span-4 text-sm font-semibold text-[#1E3F32]";

                message.textContent = "Acara Sedang Berlangsung";

                countdown.appendChild(message);
            }

            return;
        }

        const days = Math.floor(
            distance / (1000 * 60 * 60 * 24)
        );

        const hours = Math.floor(
            (distance % (1000 * 60 * 60 * 24)) /
            (1000 * 60 * 60)
        );

        const minutes = Math.floor(
            (distance % (1000 * 60 * 60)) /
            (1000 * 60)
        );

        const seconds = Math.floor(
            (distance % (1000 * 60)) / 1000
        );

        document.getElementById("days").textContent =
            String(days).padStart(2, "0");

        document.getElementById("hours").textContent =
            String(hours).padStart(2, "0");

        document.getElementById("minutes").textContent =
            String(minutes).padStart(2, "0");

        document.getElementById("seconds").textContent =
            String(seconds).padStart(2, "0");
    }

    updateCountdown();

    const countdownInterval = setInterval(() => {
        updateCountdown();

        if (Date.now() >= targetDate) {
            clearInterval(countdownInterval);
        }
    }, 1000);

    // ==========================================
    // 5. FUNGSI PENGIRIMAN API
    // ==========================================

    async function sendToAPI(url, data) {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            cache: "no-store",
            body: JSON.stringify(data)
        });

        let result;

        try {
            result = await response.json();
        } catch {
            throw new Error(
                "Respons server tidak valid. Periksa konfigurasi PHP."
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || "Terjadi kesalahan. Silakan coba lagi."
            );
        }

        return result;
    }

    // ==========================================
    // 6. FORM RSVP
    // ==========================================

    
  // 5. Penanganan Form RSVP - data disimpan ke database MySQL

  const rsvpForm = document.getElementById("rsvp-form");
  const rsvpFeedback = document.getElementById("rsvp-feedback");

  rsvpForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitButton = rsvpForm.querySelector('button[type="submit"]');
    const originalButtonText = submitButton.textContent;

    if (submitButton.disabled) return;

    // Ambil data dari formulir
    const name = document.getElementById("rsvp-name").value.trim();
    const status = document.getElementById("rsvp-status").value;

    if (!name || !status) {
      rsvpFeedback.textContent = "Nama dan status kehadiran wajib diisi.";
      rsvpFeedback.classList.remove("hidden");
      return;
    }

    submitButton.disabled = true;
    submitButton.textContent = "Mengirim Konfirmasi...";

    try {
      const result = await sendToAPI("api/submit-rsvp.php", {
        invitation: invitationSlug,
        name: name,
        status: status
      });

      rsvpFeedback.textContent = result.message;
      rsvpFeedback.classList.remove("hidden");

      rsvpForm.reset();

      if (guestParam) {
        document.getElementById("rsvp-name").value = guestParam;
      }

    } catch (error) {
      rsvpFeedback.textContent = error.message;
      rsvpFeedback.classList.remove("hidden");

    } finally {
      submitButton.disabled = false;
      submitButton.textContent = originalButtonText;
    }
  });


    // ==========================================
    // 7. FORM UCAPAN TAMU
    // ==========================================

    const wishForm = document.getElementById("wish-form");
    const wishesList = document.getElementById("wishes-list");

    function formatWishDate(dateValue) {
        const date = new Date(
            String(dateValue).replace(" ", "T")
        );

        if (Number.isNaN(date.getTime())) {
            return String(dateValue);
        }

        return date.toLocaleString("id-ID", {
            dateStyle: "medium",
            timeStyle: "short"
        });
    }

    function renderWishes(wishes) {
        wishesList.replaceChildren();

        if (!Array.isArray(wishes) || wishes.length === 0) {
            const emptyMessage = document.createElement("p");

            emptyMessage.className =
                "text-xs text-[#556860] text-center py-3";

            emptyMessage.textContent =
                "Belum ada ucapan. Jadilah yang pertama mengirimkan doa!";

            wishesList.appendChild(emptyMessage);
            return;
        }

        wishes.forEach((item) => {
            const card = document.createElement("div");

            card.className =
                "bg-[#F7FAF8] border border-[#D3E0D9] rounded-2xl p-4";

            const header = document.createElement("div");

            header.className =
                "flex justify-between items-center mb-1 gap-3";

            const name = document.createElement("span");

            name.className =
                "text-xs font-semibold text-[#1E3F32]";

            name.textContent = item.name ?? "Tamu Undangan";

            const time = document.createElement("span");

            time.className =
                "text-[11px] text-[#556860] text-right";

            time.textContent = formatWishDate(item.created_at);

            const message = document.createElement("p");

            message.className =
                "text-xs sm:text-sm text-[#1A2923] leading-relaxed whitespace-pre-wrap";

            message.textContent = item.message ?? "";

            header.append(name, time);

            card.append(header, message);

            wishesList.appendChild(card);
        });
    }

    async function loadWishes() {
        try {
            const response = await fetch("api/get-wishes.php?invitation=" + encodeURIComponent(invitationSlug), {
                method: "GET",
                headers: {
                    "Accept": "application/json"
                },
                cache: "no-store"
            });

            let result;

            try {
                result = await response.json();
            } catch {
                throw new Error("Respons server tidak valid.");
            }

            if (
                !response.ok ||
                !result.success ||
                !Array.isArray(result.data)
            ) {
                throw new Error(
                    result.message || "Ucapan belum dapat dimuat."
                );
            }

            renderWishes(result.data);

            // Ucapan terbaru ada di paling atas.
            wishesList.scrollTop = 0;

        } catch (error) {
            wishesList.textContent =
                error.message || "Gagal memuat ucapan.";
        }
    }

    function showWishFeedback(message, success) {
        let feedback = document.getElementById("wish-feedback");

        if (!feedback) {
            feedback = document.createElement("div");

            feedback.id = "wish-feedback";

            feedback.className =
                "max-w-md mx-auto mt-3 p-3 rounded-xl text-xs text-center";

            wishForm.insertAdjacentElement("afterend", feedback);
        }

        feedback.textContent = message;

        feedback.style.backgroundColor = success
            ? "#E4EDE8"
            : "#FFF0F0";

        feedback.style.color = success
            ? "#1E3F32"
            : "#A22";
    }

    
  wishForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitButton = wishForm.querySelector('button[type="submit"]');
    const originalButtonText = submitButton.textContent;

    if (submitButton.disabled) return;

    // Ambil data dari formulir
    const name = document.getElementById("wish-name").value.trim();
    const message = document.getElementById("wish-message").value.trim();

    if (!name || !message) {
      let feedback = document.getElementById("wish-feedback");

      if (!feedback) {
        feedback = document.createElement("div");
        feedback.id = "wish-feedback";
        feedback.className = "max-w-md mx-auto mt-3 p-3 rounded-xl text-xs text-center";
        wishForm.insertAdjacentElement("afterend", feedback);
      }

      feedback.textContent = "Nama dan ucapan wajib diisi.";
      feedback.style.backgroundColor = "#FFF0F0";
      feedback.style.color = "#A22";
      return;
    }

    submitButton.disabled = true;
    submitButton.textContent = "Mengirim Ucapan...";

    let feedback = document.getElementById("wish-feedback");

    if (!feedback) {
      feedback = document.createElement("div");
      feedback.id = "wish-feedback";
      feedback.className = "max-w-md mx-auto mt-3 p-3 rounded-xl text-xs text-center";
      wishForm.insertAdjacentElement("afterend", feedback);
    }

    try {
      const result = await sendToAPI("api/submit-wish.php", {
        invitation: invitationSlug,
        name: name,
        message: message
      });

      feedback.textContent = result.message;
      feedback.style.backgroundColor = "#E4EDE8";
      feedback.style.color = "#1E3F32";

      wishForm.reset();

      if (guestParam) {
        document.getElementById("wish-name").value = guestParam;
      }

      await loadWishes();

    } catch (error) {
      feedback.textContent = error.message;
      feedback.style.backgroundColor = "#FFF0F0";
      feedback.style.color = "#A22";

    } finally {
      submitButton.disabled = false;
      submitButton.textContent = originalButtonText;
    }
  });


    // ==========================================
    // 8. MUAT UCAPAN DARI DATABASE
    // ==========================================

    loadWishes();
});


// ==========================================
// 9. LIGHTBOX GALERI
// ==========================================

function openLightbox(src) {
    const modal = document.getElementById("lightbox-modal");
    const image = document.getElementById("lightbox-img");

    image.src = src;

    modal.classList.remove("hidden");
    modal.classList.add("flex");

    document.body.style.overflow = "hidden";
}

function closeLightbox() {
    const modal = document.getElementById("lightbox-modal");

    modal.classList.add("hidden");
    modal.classList.remove("flex");

    document.body.style.overflow = "";
}

// Mencegah klik gambar atau tombol di dalam modal
// langsung dianggap sebagai klik untuk menutup modal.
document.getElementById("lightbox-modal")?.firstElementChild
    ?.addEventListener("click", (event) => {
        event.stopPropagation();
    });


// ==========================================
// 10. SALIN NOMOR REKENING
// ==========================================

async function copyRekening(elementId, buttonEl) {
    const rekeningElement = document.getElementById(elementId);

    if (!rekeningElement || !buttonEl) return;

    const rekeningNumber = rekeningElement.textContent.trim();

    const originalText = buttonEl.textContent;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(rekeningNumber);
        } else {
            const tempInput = document.createElement("textarea");

            tempInput.value = rekeningNumber;
            tempInput.style.position = "fixed";
            tempInput.style.opacity = "0";

            document.body.appendChild(tempInput);

            tempInput.select();

            const copied = document.execCommand("copy");

            document.body.removeChild(tempInput);

            if (!copied) {
                throw new Error("Penyalinan gagal.");
            }
        }

        buttonEl.textContent = "✓ Berhasil Disalin!";

        buttonEl.classList.add(
            "bg-[#1E3F32]",
            "text-white"
        );

    } catch (error) {
        buttonEl.textContent = "Gagal menyalin";

    } finally {
        setTimeout(() => {
            buttonEl.textContent = originalText;

            buttonEl.classList.remove(
                "bg-[#1E3F32]",
                "text-white"
            );
        }, 2000);
    }
}// ==========================================
// YOUTUBE BACKGROUND MUSIC
// ==========================================

const YOUTUBE_VIDEO_ID = "u03oQwQVR9E";

let youtubePlayer = null;
let youtubeReady = false;
let musicStarted = false;
let musicPausedByUser = false;
let pendingMusicStart = false;

const musicToggle = document.getElementById("music-toggle");
const musicIcon = document.getElementById("music-icon");

// Callback dari YouTube IFrame API
function onYouTubeIframeAPIReady() {
    youtubePlayer = new YT.Player("youtube-player", {
        width: "1",
        height: "1",
        videoId: YOUTUBE_VIDEO_ID,

        playerVars: {
            autoplay: 0,
            controls: 0,
            loop: 1,
            playlist: YOUTUBE_VIDEO_ID,
            playsinline: 1,
            rel: 0
        },

        events: {
            onReady: function (event) {
                youtubeReady = true;
                event.target.setVolume(30);

                // Jika tombol undangan sudah diklik sebelumnya
                if (pendingMusicStart && !musicPausedByUser) {
                    startBackgroundMusic();
                }
            },

            onStateChange: function (event) {
                if (event.data === YT.PlayerState.PLAYING) {
                    musicIcon.textContent = "♫";
                    musicToggle.setAttribute("aria-label", "Jeda musik");
                    musicToggle.title = "Jeda musik";
                } else {
                    musicIcon.textContent = "▶";
                    musicToggle.setAttribute("aria-label", "Putar musik");
                    musicToggle.title = "Putar musik";
                }
            },

            onError: function (event) {
                console.error("YouTube Player Error:", event.data);
                musicIcon.textContent = "▶";
            }
        }
    });
}

// Memulai musik
function startBackgroundMusic() {
    if (!youtubeReady || !youtubePlayer || musicPausedByUser) {
        return;
    }

    pendingMusicStart = false;
    musicStarted = true;

    youtubePlayer.setVolume(30);
    youtubePlayer.playVideo();
}

// Tombol Play / Pause
musicToggle.addEventListener("click", function () {
    if (!youtubeReady || !youtubePlayer) {
        return;
    }

    const playerState = youtubePlayer.getPlayerState();

    if (playerState === YT.PlayerState.PLAYING) {
        musicPausedByUser = true;
        youtubePlayer.pauseVideo();
    } else {
        musicPausedByUser = false;
        startBackgroundMusic();
    }
});