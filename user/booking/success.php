<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/session.php';
require_once __DIR__ . '/../../backend/middleware/require_customer.php';

$userPageTitle = 'Reservasi Berhasil | Pemon Barbershop';
$userActivePage = '';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';

$apiUrl = '../../backend/api/booking_success.php';

$reservationId = filter_input(
    INPUT_GET,
    'reservation_id',
    FILTER_VALIDATE_INT
);

if ($reservationId === false || $reservationId === null || $reservationId < 1) {
    $reservationId = '';
}

require_once __DIR__ . '/../../includes/header.php';
?>

<main
    id="booking-success"
    class="mx-auto w-full max-w-3xl flex-1 py-8"
    data-api-url="<?= htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') ?>"
    data-reservation-id="<?= htmlspecialchars((string) $reservationId, ENT_QUOTES, 'UTF-8') ?>"
>
    <div id="success-loading" class="py-16 text-center">
        <p class="text-sm text-gray-600">Memuat informasi reservasi...</p>
    </div>

    <section
        id="success-error"
        class="hidden rounded-2xl border border-red-100 bg-white p-6 text-center shadow-sm"
        role="alert"
    >
        <h1 class="text-xl font-bold text-red-700">
            Reservasi belum dapat ditampilkan
        </h1>

        <p id="success-error-message" class="mt-2 text-sm text-gray-600"></p>

        <a
            href="booking.php"
            class="mt-5 inline-flex rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white"
        >
            Kembali ke booking
        </a>
    </section>

    <div id="success-content" class="hidden">
        <div class="mb-6 text-center">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#0d3b36] text-xl font-bold text-white">
                ✓
            </span>

            <h1 class="mt-4 text-2xl font-extrabold">
                Reservasi berhasil dibuat
            </h1>

            <p class="mt-2 text-sm text-gray-600">
                Simpan kode booking ini untuk kunjunganmu.
            </p>
        </div>

        <section class="space-y-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">

            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 pb-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">
                        Pemon Studio Senopati
                    </p>

                    <p class="mt-1 text-sm text-gray-600">
                        Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan
                    </p>
                </div>

                <div class="text-left sm:text-right">
                    <p class="text-xs text-gray-500">Kode booking</p>
                    <p id="booking-code" class="font-bold">-</p>
                </div>
            </div>

            <dl class="grid gap-5 text-sm sm:grid-cols-2">

                <div>
                    <dt class="text-xs text-gray-500">Layanan</dt>
                    <dd id="booking-service" class="mt-1 font-semibold">-</dd>
                </div>

                <div id="booking-additional-service-row" class="hidden">
                    <dt class="text-xs text-gray-500">Layanan tambahan</dt>
                    <dd id="booking-additional-service" class="mt-1 font-semibold">-</dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Nama pemesan</dt>
                    <dd id="booking-name" class="mt-1 font-semibold">-</dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Tanggal</dt>
                    <dd id="booking-date" class="mt-1 font-semibold">-</dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Waktu</dt>
                    <dd id="booking-time" class="mt-1 font-semibold">-</dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Nomor telepon</dt>
                    <dd id="booking-phone" class="mt-1 font-semibold">-</dd>
                </div>

                <div>
                    <dt class="text-xs text-gray-500">Total</dt>
                    <dd id="booking-total" class="mt-1 font-semibold">-</dd>
                </div>

            </dl>

            <div class="rounded-xl bg-[#f8f8fd] p-4">
                <p class="text-xs text-gray-500">Status pembayaran</p>

                <span
                    id="booking-payment-status"
                    class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                >
                    -
                </span>

                <p id="booking-payment-note" class="mt-2 hidden text-xs text-gray-600">
                    Status mengikuti pembaruan pembayaran dari Midtrans.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <a
                    id="booking-invoice-link"
                    href="#"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex flex-1 justify-center rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-bold text-white hover:bg-[#082724]"
                >
                    Cetak tiket / nota
                </a>

                <a
                    href="../dashboard/index.php"
                    class="inline-flex flex-1 justify-center rounded-xl border border-gray-200 px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Kembali ke dashboard
                </a>
            </div>

        </section>
    </div>
</main>

<script src="../../assets/js/success.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
