ALTER TABLE public.service
    ADD COLUMN IF NOT EXISTS duration_minutes integer NOT NULL DEFAULT 30;

ALTER TABLE public.service
    DROP CONSTRAINT IF EXISTS check_service_duration_minutes;

ALTER TABLE public.service
    ADD CONSTRAINT check_service_duration_minutes
    CHECK (duration_minutes BETWEEN 15 AND 240);
