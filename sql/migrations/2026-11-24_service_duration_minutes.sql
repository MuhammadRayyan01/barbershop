BEGIN;

ALTER TABLE public.service
    ADD COLUMN IF NOT EXISTS duration_minutes smallint NOT NULL DEFAULT 30;

UPDATE public.service
SET duration_minutes = 30
WHERE duration_minutes IS NULL;

ALTER TABLE public.service
    ALTER COLUMN duration_minutes SET DEFAULT 30,
    ALTER COLUMN duration_minutes SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'service_duration_minutes_range'
          AND conrelid = 'public.service'::regclass
    ) THEN
        ALTER TABLE public.service
            ADD CONSTRAINT service_duration_minutes_range
            CHECK (duration_minutes BETWEEN 15 AND 240);
    END IF;
END
$$;

COMMIT;
