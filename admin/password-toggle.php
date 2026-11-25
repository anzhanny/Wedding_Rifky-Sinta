<?php
declare(strict_types=1);

/**
 * Ikon mata untuk menampilkan/menyembunyikan password.
 *
 * Pasang di akhir halaman (sebelum </body>):
 *     <?php require __DIR__ . '/password-toggle.php'; ?>
 * Semua <input type="password"> di halaman otomatis mendapat ikon mata.
 */
?>
<style>
.pw-wrap {
    position: relative;
    display: block;
    width: 100%;
}

/* Di dalam baris flex (mis. form ganti password di tabel) */
.inline > .pw-wrap {
    flex: 1;
    width: auto;
    min-width: 150px;
}

.pw-wrap > input {
    padding-right: 44px !important;
}

.pw-eye {
    position: absolute;
    top: 50%;
    right: 6px;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pw-eye:hover,
.pw-eye:focus-visible {
    background: rgba(31, 78, 150, .1);
    color: #1f4e96;
    outline: none;
}

.pw-eye svg {
    width: 20px;
    height: 20px;
    display: block;
}

.pw-eye .eye-off {
    display: none;
}

.pw-eye.is-visible .eye-on {
    display: none;
}

.pw-eye.is-visible .eye-off {
    display: block;
}
</style>

<script>
(function () {
    var EYE =
        '<svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>' +
        '<circle cx="12" cy="12" r="3"/></svg>' +
        '<svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M17.94 17.94A10.9 10.9 0 0 1 12 19c-6.4 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/>' +
        '<path d="M9.9 4.24A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/>' +
        '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>' +
        '<line x1="2" y1="2" x2="22" y2="22"/></svg>';

    function addEye(input) {
        if (input.dataset.pwEye === '1') return;
        input.dataset.pwEye = '1';

        // Pindahkan margin kolom ke pembungkus agar ikon tetap sejajar dengan kolom.
        var cs = window.getComputedStyle(input);
        var wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        wrap.style.marginTop = cs.marginTop;
        wrap.style.marginBottom = cs.marginBottom;
        input.style.margin = '0';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'pw-eye';
        button.setAttribute('aria-label', 'Tampilkan password');
        button.setAttribute('title', 'Tampilkan / sembunyikan password');
        button.innerHTML = EYE;
        wrap.appendChild(button);

        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.classList.toggle('is-visible', show);
            button.setAttribute(
                'aria-label',
                show ? 'Sembunyikan password' : 'Tampilkan password'
            );
            input.focus();
        });
    }

    document.querySelectorAll('input[type="password"]').forEach(addEye);

    // Sembunyikan lagi sebelum form dikirim agar tidak tersimpan sebagai teks di riwayat.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('.pw-eye.is-visible').forEach(function (b) {
                b.click();
            });
        });
    });
})();
</script>
