--
-- PostgreSQL database dump
--

\restrict quhg5JbWBGHo3Imfeif1iGZbH3E1wKbpxcTBXWmHnfM7aPZ6WBkwojA6BbTeykp

-- Dumped from database version 18.6 (Debian 18.6-3)
-- Dumped by pg_dump version 18.6 (Debian 18.6-3)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- *not* creating schema, since initdb creates it


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS '';


--
-- Name: pay_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.pay_status AS ENUM (
    'pending',
    'paid',
    'verification pending',
    'failed',
    'refunded',
    'cancelled'
);


--
-- Name: res_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.res_status AS ENUM (
    'active',
    'inactive'
);


--
-- Name: service_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.service_status AS ENUM (
    'active',
    'inactive'
);


--
-- Name: slot_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.slot_status AS ENUM (
    'available',
    'not available'
);


--
-- Name: check_category_slot_availability(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.check_category_slot_availability() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    target_slot_date DATE;
    target_start_time TIME;
    booked_count INTEGER;
    hourly_lock_key TEXT;
BEGIN
    -- Retrieve the date and time of the selected slot.
    SELECT
        slot_date,
        start_time
    INTO
        target_slot_date,
        target_start_time
    FROM time_slot
    WHERE slot_id = NEW.slot_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Selected time slot does not exist.';
    END IF;

    -- Both half-hour slots within an hour use the same lock key.
    hourly_lock_key :=
        target_slot_date::TEXT
        || ':'
        || LPAD(EXTRACT(HOUR FROM target_start_time)::INTEGER::TEXT, 2, '0');

    -- Serialize permanent reservations for this date and hour.
    PERFORM pg_advisory_xact_lock(
        hashtextextended(hourly_lock_key, 0)
    );

    -- Count active reservations across both half-hour slots.
    SELECT COUNT(*)
    INTO booked_count
    FROM reservation r
    INNER JOIN time_slot ts
        ON ts.slot_id = r.slot_id
    WHERE ts.slot_date = target_slot_date
      AND EXTRACT(HOUR FROM ts.start_time)
          = EXTRACT(HOUR FROM target_start_time)
      AND r.reservation_status = 'active'
      AND r.reservation_id IS DISTINCT FROM NEW.reservation_id;

    -- Maximum of three permanent reservations per clock hour.
    IF booked_count >= 3 THEN
        RAISE EXCEPTION
            'This hour is fully booked. Maximum capacity is 3 bookings per hour.';
    END IF;

    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: additional_service; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.additional_service (
    additional_service_id integer NOT NULL,
    service_id integer,
    additional_service_name character varying(255) NOT NULL,
    additional_service_price numeric(10,2) NOT NULL
);


--
-- Name: additional_service_additional_service_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.additional_service_additional_service_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: additional_service_additional_service_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.additional_service_additional_service_id_seq OWNED BY public.additional_service.additional_service_id;


--
-- Name: admin; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin (
    admin_id integer NOT NULL,
    username character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(255) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: admin_admin_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_admin_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_admin_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_admin_id_seq OWNED BY public.admin.admin_id;


--
-- Name: booking_hold; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.booking_hold (
    hold_id bigint NOT NULL,
    hold_token character varying(64) NOT NULL,
    user_id integer NOT NULL,
    slot_id integer NOT NULL,
    service_id integer NOT NULL,
    service_price numeric(10,2) NOT NULL,
    additional_service_id integer,
    additional_service_price numeric(10,2),
    total_amount numeric(10,2) NOT NULL,
    hold_status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    midtrans_order_id character varying(255),
    snap_token text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    reservation_id integer,
    CONSTRAINT booking_hold_hold_status_check CHECK (((hold_status)::text = ANY ((ARRAY['active'::character varying, 'converted'::character varying, 'expired'::character varying, 'released'::character varying, 'failed'::character varying])::text[]))),
    CONSTRAINT booking_hold_service_price_check CHECK ((service_price >= (0)::numeric)),
    CONSTRAINT booking_hold_total_amount_check CHECK ((total_amount > (0)::numeric)),
    CONSTRAINT chk_booking_hold_additional_price CHECK ((((additional_service_id IS NULL) AND (additional_service_price IS NULL)) OR ((additional_service_id IS NOT NULL) AND (additional_service_price IS NOT NULL) AND (additional_service_price >= (0)::numeric)))),
    CONSTRAINT chk_booking_hold_total CHECK ((total_amount = (service_price + COALESCE(additional_service_price, (0)::numeric))))
);


--
-- Name: booking_hold_hold_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.booking_hold ALTER COLUMN hold_id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.booking_hold_hold_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: invoice; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice (
    invoice_id integer NOT NULL,
    reservation_id integer NOT NULL,
    customer_name character varying(255) NOT NULL,
    phone_number character varying(15) NOT NULL,
    total_payment numeric(10,2) NOT NULL,
    cancellation_deadline timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT check_invoice_total CHECK ((total_payment >= (0)::numeric))
);


--
-- Name: invoice_invoice_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.invoice_invoice_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoice_invoice_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.invoice_invoice_id_seq OWNED BY public.invoice.invoice_id;


--
-- Name: payment; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment (
    payment_id integer NOT NULL,
    reservation_id integer NOT NULL,
    payment_type character varying(100) NOT NULL,
    payment_status public.pay_status DEFAULT 'pending'::public.pay_status NOT NULL,
    amount numeric(10,2) NOT NULL,
    transaction_reference character varying(255),
    refund_method character varying(20) DEFAULT 'gateway'::character varying NOT NULL,
    admin_cancel_outcome character varying(32),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    snap_token text
);

ALTER TABLE ONLY public.payment
    ADD CONSTRAINT payment_refund_method_check
    CHECK (((refund_method)::text = ANY ((ARRAY['gateway'::character varying, 'manual'::character varying])::text[])));

ALTER TABLE ONLY public.payment
    ADD CONSTRAINT payment_admin_cancel_outcome_check
    CHECK ((admin_cancel_outcome IS NULL OR (admin_cancel_outcome)::text = ANY ((ARRAY['no_payment'::character varying, 'pending_cancelled'::character varying, 'authorization_voided'::character varying, 'capture_voided'::character varying])::text[])));


--
-- Name: payment_payment_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.payment_payment_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: payment_payment_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.payment_payment_id_seq OWNED BY public.payment.payment_id;


--
-- Name: reservation; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reservation (
    reservation_id integer NOT NULL,
    user_id integer NOT NULL,
    slot_id integer NOT NULL,
    service_id integer NOT NULL,
    service_price numeric(10,2),
    additional_service_id integer,
    additional_service_price numeric(10,2),
    reservation_status public.res_status DEFAULT 'active'::public.res_status NOT NULL,
    admin_cancelled_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT check_reservation_service_additional_price CHECK ((additional_service_price >= (0)::numeric)),
    CONSTRAINT check_reservation_service_price CHECK ((service_price >= (0)::numeric))
);


--
-- Name: reservation_additional_services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reservation_additional_services (
    reservation_id integer NOT NULL,
    additional_service_id integer NOT NULL,
    price numeric(10,2) NOT NULL,
    CONSTRAINT check_ras_price CHECK ((price >= (0)::numeric))
);


--
-- Name: reservation_reservation_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.reservation_reservation_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reservation_reservation_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.reservation_reservation_id_seq OWNED BY public.reservation.reservation_id;


--
-- Name: service; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.service (
    service_id integer NOT NULL,
    service_name character varying(255) NOT NULL,
    description text,
    price numeric(10,2) NOT NULL,
    duration_minutes integer DEFAULT 30 NOT NULL,
    status public.service_status DEFAULT 'active'::public.service_status NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    category character varying(20) DEFAULT 'regular'::character varying NOT NULL,
    CONSTRAINT check_service_duration_minutes CHECK (((duration_minutes >= 15) AND (duration_minutes <= 240))),
    CONSTRAINT service_category_check CHECK (((category)::text = ANY (ARRAY[('premium'::character varying)::text, ('regular'::character varying)::text])))
);


--
-- Name: service_service_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.service_service_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: service_service_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.service_service_id_seq OWNED BY public.service.service_id;


--
-- Name: shop_notice; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.shop_notice (
    notice_id integer NOT NULL,
    admin_id integer NOT NULL,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    start_date date,
    end_date date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: shop_notice_notice_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.shop_notice_notice_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: shop_notice_notice_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.shop_notice_notice_id_seq OWNED BY public.shop_notice.notice_id;


--
-- Name: time_slot; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.time_slot (
    slot_id integer NOT NULL,
    slot_date date NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    is_available public.slot_status DEFAULT 'available'::public.slot_status NOT NULL,
    booking_capacity smallint DEFAULT 3 NOT NULL,
    CONSTRAINT time_slot_booking_capacity_range CHECK ((booking_capacity >= 0) AND (booking_capacity <= 3)),
    CONSTRAINT check_slot_time CHECK ((end_time > start_time))
);


--
-- Name: time_slot_slot_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.time_slot_slot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: time_slot_slot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.time_slot_slot_id_seq OWNED BY public.time_slot.slot_id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id integer NOT NULL,
    username character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(255) NOT NULL,
    phone_number character varying(15) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_user_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_user_id_seq OWNED BY public.users.user_id;


--
-- Name: additional_service additional_service_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.additional_service ALTER COLUMN additional_service_id SET DEFAULT nextval('public.additional_service_additional_service_id_seq'::regclass);


--
-- Name: admin admin_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin ALTER COLUMN admin_id SET DEFAULT nextval('public.admin_admin_id_seq'::regclass);


--
-- Name: invoice invoice_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice ALTER COLUMN invoice_id SET DEFAULT nextval('public.invoice_invoice_id_seq'::regclass);


--
-- Name: payment payment_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment ALTER COLUMN payment_id SET DEFAULT nextval('public.payment_payment_id_seq'::regclass);


--
-- Name: reservation reservation_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation ALTER COLUMN reservation_id SET DEFAULT nextval('public.reservation_reservation_id_seq'::regclass);


--
-- Name: service service_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service ALTER COLUMN service_id SET DEFAULT nextval('public.service_service_id_seq'::regclass);


--
-- Name: shop_notice notice_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_notice ALTER COLUMN notice_id SET DEFAULT nextval('public.shop_notice_notice_id_seq'::regclass);


--
-- Name: time_slot slot_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.time_slot ALTER COLUMN slot_id SET DEFAULT nextval('public.time_slot_slot_id_seq'::regclass);


--
-- Name: users user_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN user_id SET DEFAULT nextval('public.users_user_id_seq'::regclass);


--
-- Name: additional_service additional_service_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.additional_service
    ADD CONSTRAINT additional_service_pkey PRIMARY KEY (additional_service_id);


--
-- Name: admin admin_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin
    ADD CONSTRAINT admin_email_key UNIQUE (email);


--
-- Name: admin admin_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin
    ADD CONSTRAINT admin_pkey PRIMARY KEY (admin_id);


--
-- Name: admin admin_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin
    ADD CONSTRAINT admin_username_key UNIQUE (username);


--
-- Name: booking_hold booking_hold_hold_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT booking_hold_hold_token_key UNIQUE (hold_token);


--
-- Name: booking_hold booking_hold_midtrans_order_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT booking_hold_midtrans_order_id_key UNIQUE (midtrans_order_id);


--
-- Name: booking_hold booking_hold_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT booking_hold_pkey PRIMARY KEY (hold_id);


--
-- Name: booking_hold booking_hold_reservation_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT booking_hold_reservation_id_key UNIQUE (reservation_id);


--
-- Name: invoice invoice_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_pkey PRIMARY KEY (invoice_id);


--
-- Name: invoice invoice_reservation_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT invoice_reservation_id_key UNIQUE (reservation_id);


--
-- Name: payment payment_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment
    ADD CONSTRAINT payment_pkey PRIMARY KEY (payment_id);


--
-- Name: payment payment_reservation_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment
    ADD CONSTRAINT payment_reservation_id_key UNIQUE (reservation_id);


--
-- Name: reservation_additional_services reservation_additional_services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation_additional_services
    ADD CONSTRAINT reservation_additional_services_pkey PRIMARY KEY (reservation_id, additional_service_id);


--
-- Name: reservation reservation_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT reservation_pkey PRIMARY KEY (reservation_id);


--
-- Name: service service_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.service
    ADD CONSTRAINT service_pkey PRIMARY KEY (service_id);


--
-- Name: shop_notice shop_notice_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_notice
    ADD CONSTRAINT shop_notice_pkey PRIMARY KEY (notice_id);


--
-- Name: time_slot time_slot_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.time_slot
    ADD CONSTRAINT time_slot_pkey PRIMARY KEY (slot_id);


--
-- Name: time_slot unique_slot_datetime; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.time_slot
    ADD CONSTRAINT unique_slot_datetime UNIQUE (slot_date, start_time, end_time);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: idx_booking_hold_expiration; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_booking_hold_expiration ON public.booking_hold USING btree (expires_at) WHERE ((hold_status)::text = 'active'::text);


--
-- Name: idx_booking_hold_slot_status_expiry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_booking_hold_slot_status_expiry ON public.booking_hold USING btree (slot_id, hold_status, expires_at);


--
-- Name: idx_booking_hold_user_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_booking_hold_user_status ON public.booking_hold USING btree (user_id, hold_status);


--
-- Name: reservation reservation_category_slot_check; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER reservation_category_slot_check BEFORE INSERT OR UPDATE OF slot_id, service_id, reservation_status ON public.reservation FOR EACH ROW WHEN ((new.reservation_status = 'active'::public.res_status)) EXECUTE FUNCTION public.check_category_slot_availability();


--
-- Name: booking_hold booking_hold_reservation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT booking_hold_reservation_id_fkey FOREIGN KEY (reservation_id) REFERENCES public.reservation(reservation_id);


--
-- Name: booking_hold fk_booking_hold_additional_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT fk_booking_hold_additional_service FOREIGN KEY (additional_service_id) REFERENCES public.additional_service(additional_service_id);


--
-- Name: booking_hold fk_booking_hold_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT fk_booking_hold_service FOREIGN KEY (service_id) REFERENCES public.service(service_id);


--
-- Name: booking_hold fk_booking_hold_slot; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT fk_booking_hold_slot FOREIGN KEY (slot_id) REFERENCES public.time_slot(slot_id);


--
-- Name: booking_hold fk_booking_hold_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.booking_hold
    ADD CONSTRAINT fk_booking_hold_user FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: invoice fk_invoice_reservation; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice
    ADD CONSTRAINT fk_invoice_reservation FOREIGN KEY (reservation_id) REFERENCES public.reservation(reservation_id);


--
-- Name: shop_notice fk_notice_admin; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_notice
    ADD CONSTRAINT fk_notice_admin FOREIGN KEY (admin_id) REFERENCES public.admin(admin_id);


--
-- Name: payment fk_payment_reservation; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment
    ADD CONSTRAINT fk_payment_reservation FOREIGN KEY (reservation_id) REFERENCES public.reservation(reservation_id);


--
-- Name: reservation_additional_services fk_ras_reservation; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation_additional_services
    ADD CONSTRAINT fk_ras_reservation FOREIGN KEY (reservation_id) REFERENCES public.reservation(reservation_id);


--
-- Name: reservation_additional_services fk_ras_reservation_additional_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation_additional_services
    ADD CONSTRAINT fk_ras_reservation_additional_service FOREIGN KEY (additional_service_id) REFERENCES public.additional_service(additional_service_id);


--
-- Name: reservation fk_reservation_additional; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT fk_reservation_additional FOREIGN KEY (additional_service_id) REFERENCES public.additional_service(additional_service_id);


--
-- Name: reservation fk_reservation_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT fk_reservation_service FOREIGN KEY (service_id) REFERENCES public.service(service_id);


--
-- Name: reservation fk_reservation_slot; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT fk_reservation_slot FOREIGN KEY (slot_id) REFERENCES public.time_slot(slot_id);


--
-- Name: reservation fk_reservation_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservation
    ADD CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: additional_service fk_service; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.additional_service
    ADD CONSTRAINT fk_service FOREIGN KEY (service_id) REFERENCES public.service(service_id);


--
-- PostgreSQL database dump complete
--

\unrestrict quhg5JbWBGHo3Imfeif1iGZbH3E1wKbpxcTBXWmHnfM7aPZ6WBkwojA6BbTeykp

