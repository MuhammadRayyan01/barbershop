<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/csrf.php';

$csrfToken = getCsrfToken();

$userPageTitle = 'Konfirmasi Reservasi | Pemon Barbershop';
$userActivePage = 'confirmation';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userShowProfileEdit = false;
$userPageScripts = ['../../assets/js/confirmation.js'];

require __DIR__ . '/../../includes/header.php';
?>

<main class="mx-auto w-full max-w-3xl py-8">

    <p class="text-center text-xs font-semibold uppercase tracking-widest text-gray-500">
        Langkah 3 dari 4
    </p>

    <h1 class="mt-2 text-center text-2xl font-extrabold">
        Konfirmasi Reservasi
    </h1>

    <p class="mt-2 text-center text-sm text-gray-600">
        Periksa layanan dan jadwal sebelum melanjutkan pembayaran.
    </p>

    <section class="mt-7 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-7">

        <div id="confirmation-loading" class="py-8 text-center text-sm text-gray-500">
            Memuat detail reservasi...
        </div>

        <div id="confirmation-content" class="hidden">

            <dl class="grid gap-5 sm:grid-cols-2">

                <div>
                    <dt class="text-xs text-gray-500">Layanan</dt>
                    <dd id="confirm-service-name" class="mt-1 font-bold"></dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Pelanggan</dt>
                    <dd id="confirm-customer-name" class="mt-1 font-semibold"></dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Nomor telepon</dt>
                    <dd id="confirm-phone" class="mt-1 font-semibold"></dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Tanggal</dt>
                    <dd id="confirm-date" class="mt-1 font-semibold"></dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Waktu</dt>
                    <dd id="confirm-time" class="mt-1 font-semibold"></dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Total pembayaran</dt>
                    <dd id="confirm-total" class="mt-1 font-bold"></dd>
                </div>

            </dl>

            <p class="mt-6 rounded-xl bg-[#f4f7f5] p-4 text-xs leading-relaxed text-gray-600">
                Periksa kembali detail reservasi Anda sebelum melanjutkan ke pembayaran.
            </p>

            <form
                id="confirmation-form"
                data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                class="mt-6 flex flex-col-reverse justify-between gap-3 sm:flex-row"
            >

                <a
                    href="booking.php"
                    class="rounded-xl border border-gray-300 px-5 py-3 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Kembali ke Booking
                </a>

                <button
                    id="confirm-submit"
                    type="submit"
                    class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-bold text-white hover:bg-[#082724]"
                >
                    Konfirmasi &amp; Lanjut ke Pembayaran
                </button>

            </form>

        </div>

        <p
            id="confirmation-message"
            class="mt-4 hidden rounded-xl px-4 py-3 text-sm"
            role="status"
            aria-live="polite"
        ></p>

    </section>

</main>

<?php require __DIR__ . '/../../includes/footer.php'; ?>