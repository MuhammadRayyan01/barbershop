BEGIN;

ALTER TABLE public.booking_hold
    ADD COLUMN IF NOT EXISTS customer_message varchar(500);

ALTER TABLE public.reservation
    ADD COLUMN IF NOT EXISTS customer_message varchar(500);

COMMIT;
