BEGIN;

-- Index the text used in database-backed search fields. Keep the config
-- language-neutral because service/customer names may be Indonesian or English.
CREATE INDEX IF NOT EXISTS idx_users_full_name_fts
    ON public.users USING GIN (
        to_tsvector('simple', COALESCE(full_name, ''))
    );

CREATE INDEX IF NOT EXISTS idx_service_name_description_fts
    ON public.service USING GIN (
        to_tsvector(
            'simple',
            COALESCE(service_name, '') || ' ' || COALESCE(description, '')
        )
    );

CREATE INDEX IF NOT EXISTS idx_invoice_customer_name_fts
    ON public.invoice USING GIN (
        to_tsvector('simple', COALESCE(customer_name, ''))
    );

COMMIT;
