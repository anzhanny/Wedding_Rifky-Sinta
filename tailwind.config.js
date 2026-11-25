/**
 * Konfigurasi Tailwind CSS untuk halaman undangan.
 * Hanya dipakai saat MEMBANGUN css/tailwind.css (tidak ikut diunggah ke hosting).
 *
 * Bangun ulang setiap kali Anda menambah/mengubah class Tailwind di index.php
 * atau js/script.js:   npm run build:css
 */
module.exports = {
  content: ["./index.php", "./js/script.js"],
  theme: {
    extend: {},
  },
  plugins: [],
};
