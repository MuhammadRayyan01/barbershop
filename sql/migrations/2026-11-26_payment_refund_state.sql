BEGIN;

-- Refund processing fields consumed by cancel_reservation.php,
-- admin_refunds.php, and Midtrans refund reconciliation.
ALTER TABLE public.payment
    ADD COLUMN IF NOT EXISTS refund_status varchar(20) NOT NULL DEFAULT 'not_requested',
    ADD COLUMN IF NOT EXISTS refund_key varchar(255),
    ADD COLUMN IF NOT EXISTS refund_reason text,
    ADD COLUMN IF NOT EXISTS refund_requested_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS refunded_at timestamp without time zone;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.payment'::regclass
          AND conname = 'payment_refund_status_check'
    ) THEN
        ALTER TABLE public.payment
            ADD CONSTRAINT payment_refund_status_check
            CHECK (refund_status IN ('not_requested', 'pending', 'completed', 'failed'));
    END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_payment_pending_refund_requested
    ON public.payment (refund_requested_at)
    WHERE refund_status = 'pending';

COMMIT;
