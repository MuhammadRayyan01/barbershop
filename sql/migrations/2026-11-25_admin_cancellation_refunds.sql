ALTER TYPE public.pay_status ADD VALUE IF NOT EXISTS 'cancelled';

ALTER TABLE public.reservation
    ADD COLUMN IF NOT EXISTS admin_cancelled_at timestamp without time zone;
