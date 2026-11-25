-- Demo data for the Pemon Barbershop admin revenue/reservation pages.
-- Creates 18 active reservations and matching invoices/paid dummy payments.
-- Run this once in the barber_shop database. The generated users cannot log in.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.service WHERE status::text = 'active'
    ) THEN
        RAISE EXCEPTION 'No active service found. Add/activate a service first.';
    END IF;
END
$$;

WITH
base AS (
    SELECT (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')::date AS today
),
schedule(day_offset, day_key, day_label, customer_count) AS (
    VALUES
        (-1, 'yesterday', 'Kemarin', 5),
        ( 0, 'today',     'Hari Ini', 4),
        ( 1, 'tomorrow',  'Besok', 3),
        ( 2, 'day_after', 'Lusa', 6)
),
customers AS (
    SELECT
        s.day_offset,
        s.day_key,
        s.day_label,
        g.customer_no,
        'dummy_' || to_char(b.today, 'YYYYMMDD') || '_' || s.day_key || '_' || lpad(g.customer_no::text, 2, '0') AS username,
        'dummy_' || to_char(b.today, 'YYYYMMDD') || '_' || s.day_key || '_' || lpad(g.customer_no::text, 2, '0') || '@example.test' AS email,
        'Dummy ' || s.day_label || ' ' || lpad(g.customer_no::text, 2, '0') AS full_name,
        '0812' || to_char(b.today, 'YYMMDD') ||
            CASE s.day_offset WHEN -1 THEN '0' WHEN 0 THEN '1' WHEN 1 THEN '2' ELSE '3' END ||
            lpad(g.customer_no::text, 2, '0') AS phone_number,
        b.today
    FROM schedule s
    CROSS JOIN base b
    CROSS JOIN LATERAL generate_series(1, s.customer_count) AS g(customer_no)
),
active_service AS (
    SELECT service_id, price
    FROM public.service
    WHERE status::text = 'active'
    ORDER BY service_id
    LIMIT 1
),
inserted_users AS (
    INSERT INTO public.users (username, email, password_hash, full_name, phone_number)
    SELECT username, email, 'dummy-data-no-login', full_name, phone_number
    FROM customers
    ON CONFLICT (username) DO UPDATE
        SET full_name = EXCLUDED.full_name
    RETURNING user_id, username, full_name, phone_number
),
inserted_slots AS (
    INSERT INTO public.time_slot (slot_date, start_time, end_time)
    SELECT
        c.today + c.day_offset,
        TIME '09:00' + (c.customer_no - 1) * INTERVAL '1 hour',
        TIME '10:00' + (c.customer_no - 1) * INTERVAL '1 hour'
    FROM customers c
    ON CONFLICT (slot_date, start_time, end_time) DO UPDATE
        SET is_available = time_slot.is_available
    RETURNING slot_id, slot_date, start_time, end_time
),
new_reservations AS (
    INSERT INTO public.reservation (
        user_id,
        slot_id,
        service_id,
        service_price,
        additional_service_id,
        additional_service_price,
        reservation_status
    )
    SELECT
        u.user_id,
        ts.slot_id,
        s.service_id,
        s.price,
        NULL,
        NULL,
        'active'
    FROM customers c
    JOIN inserted_users u ON u.username = c.username
    JOIN inserted_slots ts
      ON ts.slot_date = c.today + c.day_offset
     AND ts.start_time = TIME '09:00' + (c.customer_no - 1) * INTERVAL '1 hour'
     AND ts.end_time = TIME '10:00' + (c.customer_no - 1) * INTERVAL '1 hour'
    CROSS JOIN active_service s
    WHERE NOT EXISTS (
        SELECT 1
        FROM public.reservation existing
        WHERE existing.user_id = u.user_id
          AND existing.slot_id = ts.slot_id
          AND existing.service_id = s.service_id
    )
    RETURNING reservation_id, user_id, slot_id, service_price
),
new_invoices AS (
    INSERT INTO public.invoice (
        reservation_id,
        customer_name,
        phone_number,
        total_payment,
        cancellation_deadline
    )
    SELECT
        r.reservation_id,
        u.full_name,
        u.phone_number,
        r.service_price,
        ts.slot_date + ts.start_time
    FROM new_reservations r
    JOIN inserted_users u ON u.user_id = r.user_id
    JOIN inserted_slots ts ON ts.slot_id = r.slot_id
    RETURNING reservation_id, total_payment
),
new_payments AS (
    INSERT INTO public.payment (
        reservation_id,
        payment_type,
        payment_status,
        amount,
        transaction_reference
    )
    SELECT
        reservation_id,
        'Dummy',
        'paid',
        total_payment,
        'DUMMY-' || reservation_id::text
    FROM new_invoices
    RETURNING payment_id, reservation_id, payment_type, payment_status, amount
)
SELECT *
FROM new_payments
ORDER BY reservation_id;

COMMIT;
