-- Removes slot_id uniqueness and indexes active reservations.
BEGIN;

DO $$
DECLARE
    unique_constraint RECORD;
BEGIN
    FOR unique_constraint IN
        SELECT constraint_row.conname
        FROM pg_constraint AS constraint_row
        WHERE constraint_row.conrelid = 'public.reservation'::regclass
          AND constraint_row.contype = 'u'
          AND (
              SELECT array_agg(attribute_row.attname::text ORDER BY attribute_row.attnum)
              FROM unnest(constraint_row.conkey) AS key_column(attnum)
              JOIN pg_attribute AS attribute_row
                ON attribute_row.attrelid = constraint_row.conrelid
               AND attribute_row.attnum = key_column.attnum
          ) = ARRAY['slot_id']::text[]
    LOOP
        EXECUTE format(
            'ALTER TABLE public.reservation DROP CONSTRAINT %I',
            unique_constraint.conname
        );
    END LOOP;
END
$$;

CREATE INDEX IF NOT EXISTS idx_reservation_active_slot
    ON public.reservation (slot_id)
    WHERE reservation_status = 'active';

COMMIT;
