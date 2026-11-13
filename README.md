Commit Requirement: fill the commit message with one sentence that explain the context of the modification or the creation of file

## Midtrans setup

Copy `.env.example` to `.env`, then set your Midtrans Server Key and the public application base URL. The checkout endpoint uses the base URL to return customers to `user/booking/success.php` after checkout.

Set the Midtrans notification URL to `https://your-domain/backend/api/midtrans_notification.php`. The notification URL must be reachable by Midtrans; localhost needs a public HTTPS tunnel during sandbox development.

Run `sql/migrations/2026-11-13_midtrans_snap_token.sql` once on an existing database before using Midtrans checkout.


