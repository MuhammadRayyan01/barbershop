ALTER TABLE public.payment
    ADD COLUMN IF NOT EXISTS admin_cancel_outcome varchar(32);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'payment_admin_cancel_outcome_check'
          AND conrelid = 'public.payment'::regclass
    ) THEN
        ALTER TABLE public.payment
            ADD CONSTRAINT payment_admin_cancel_outcome_check
            CHECK (
                admin_cancel_outcome IS NULL
                OR admin_cancel_outcome IN (
                    'no_payment',
                    'pending_cancelled',
                    'authorization_voided',
                    'capture_voided'
                )
            );
    END IF;
END;
$$;
