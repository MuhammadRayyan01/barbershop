-- Allow admin to set the number of bookings/barbers available for each hour.
-- Hourly booking rows use this capacity; legacy half-hour records remain counted by hour.
ALTER TABLE public.time_slot
    ADD COLUMN IF NOT EXISTS booking_capacity smallint NOT NULL DEFAULT 3;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'time_slot_booking_capacity_range'
          AND conrelid = 'public.time_slot'::regclass
    ) THEN
        ALTER TABLE public.time_slot
            ADD CONSTRAINT time_slot_booking_capacity_range
            CHECK (booking_capacity BETWEEN 0 AND 3);
    END IF;
END;
$$;
