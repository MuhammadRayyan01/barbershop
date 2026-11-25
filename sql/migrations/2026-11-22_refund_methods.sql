BEGIN;

-- Preserve the existing refund state machine while distinguishing refunds
-- that Midtrans will process from refunds the merchant must send manually.
ALTER TABLE public.payment
    ADD COLUMN IF NOT EXISTS refund_method varchar(20) NOT NULL DEFAULT 'gateway';

UPDATE public.payment
SET refund_method = 'gateway'
WHERE refund_method IS NULL OR refund_method = '';

ALTER TABLE public.payment
    DROP CONSTRAINT IF EXISTS payment_refund_method_check;

ALTER TABLE public.payment
    ADD CONSTRAINT payment_refund_method_check
    CHECK (refund_method IN ('gateway', 'manual'));

COMMIT;
