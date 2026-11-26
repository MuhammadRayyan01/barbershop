

create table if not exists users(
        user_id serial primary key,
        username varchar(255) unique not null,
        email varchar(255) unique not null,
        password_hash varchar(255) not null,
        full_name varchar(255) not null,
        phone_number varchar(15) not null,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null
);

create table if not exists admin(
        admin_id serial primary key,
        username varchar(255) unique not null,
        email varchar(255) unique not null,
        password_hash varchar(255) not null,
        full_name varchar(255) not null,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null
);

create type service_status as enum('active','inactive');
create table if not exists service(
        service_id serial primary key,
        service_name varchar(255) not null,
        description text,
        price decimal (10, 2) not null,
        duration_minutes int not null default 30,
        status service_status default 'active'  not null,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null,
        constraint check_service_duration_minutes check(duration_minutes between 15 and 240)
);

create table if not exists additional_service (
        additional_service_id serial primary key,
        service_id int,
        additional_service_name varchar(255) not null,
        additional_service_price decimal(10, 2) not null,
        
        constraint fk_service foreign key (service_id) references service (service_id)
);

create type slot_status as enum ('available', 'not available');
create table if not exists time_slot(
        slot_id serial primary key,
        slot_date date not null,
        start_time time not null,
        end_time time not null,
        is_available slot_status default 'available' not null,
        booking_capacity smallint not null default 3 check (booking_capacity between 0 and 3),

        constraint check_slot_time check(end_time > start_time),
        constraint unique_slot_datetime unique(slot_date, start_time, end_time)
);

create type res_status as enum('active', 'inactive');
create table if not exists reservation(
        reservation_id serial primary key,
        user_id int not null,
        slot_id int not null,
        service_id int not null,
        service_price decimal(10, 2),
        additional_service_id int,
        additional_service_price decimal(10, 2),
        customer_message varchar(500),
        reservation_status res_status default 'active' not null,
        admin_cancelled_at timestamp,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null,

        constraint fk_reservation_user foreign key (user_id) references users (user_id),
        constraint fk_reservation_slot foreign key (slot_id) references time_slot (slot_id),
        constraint fk_reservation_service foreign key (service_id) references service (service_id),
        constraint fk_reservation_additional foreign key (additional_service_id) references additional_service (additional_service_id),

        constraint check_reservation_service_price check(service_price >= 0),
        constraint check_reservation_service_additional_price check(additional_service_price >= 0)
);

create index if not exists idx_reservation_active_slot
        on reservation (slot_id)
        where reservation_status = 'active';

create table if not exists reservation_additional_services(
        reservation_id int not null,
        additional_service_id int not null,
        price decimal (10, 2) not null,

        primary key (reservation_id, additional_service_id),

        constraint fk_ras_reservation foreign key (reservation_id) references reservation(reservation_id),
        constraint fk_ras_reservation_additional_service foreign key (additional_service_id)references additional_service (additional_service_id),

        constraint check_ras_price check (price >= 0)
);

create type pay_status as enum('pending','paid','verification pending','failed','refunded','cancelled');
create table if not exists payment(
        payment_id serial primary key,
        reservation_id int unique not null,
        payment_type varchar(100) not null,
        payment_status pay_status default 'pending' not null,
        amount decimal(10, 2) not null,
        payment_proof_url varchar(255),
        transaction_reference varchar(255),
        refund_method varchar(20) not null default 'gateway' check (refund_method in ('gateway', 'manual')),
        refund_status varchar(20) not null default 'not_requested' check (refund_status in ('not_requested', 'pending', 'completed', 'failed')),
        refund_key varchar(255),
        refund_reason text,
        refund_requested_at timestamp,
        refunded_at timestamp,
        admin_cancel_outcome varchar(32) check (admin_cancel_outcome is null or admin_cancel_outcome in ('no_payment', 'pending_cancelled', 'authorization_voided', 'capture_voided')),
        snap_token varchar(255),
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null,

        constraint fk_payment_reservation foreign key (reservation_id) references reservation (reservation_id)
);

create table if not exists invoice(
        invoice_id serial primary key,
        reservation_id int unique not null,
        customer_name varchar(255) not null,
        phone_number varchar(15) not null,
        total_payment decimal(10, 2) not null,
        cancellation_deadline timestamp not null,
        created_at timestamp default current_timestamp not null,

        constraint fk_invoice_reservation foreign key (reservation_id) references reservation (reservation_id),
        constraint check_invoice_total check (total_payment >= 0)
);

create table if not exists booking_hold(
        hold_id bigint generated by default as identity primary key,
        hold_token varchar(64) not null unique,
        user_id int not null,
        slot_id int not null,
        service_id int not null,
        service_price decimal(10, 2) not null,
        additional_service_id int,
        additional_service_price decimal(10, 2),
        customer_message varchar(500),
        total_amount decimal(10, 2) not null,
        hold_status varchar(20) not null default 'active',
        expires_at timestamptz not null,
        midtrans_order_id varchar(255) unique,
        snap_token text,
        created_at timestamptz not null default current_timestamp,
        updated_at timestamptz not null default current_timestamp,
        reservation_id int unique,

        constraint fk_booking_hold_user foreign key (user_id) references users(user_id),
        constraint fk_booking_hold_slot foreign key (slot_id) references time_slot(slot_id),
        constraint fk_booking_hold_service foreign key (service_id) references service(service_id),
        constraint fk_booking_hold_additional_service foreign key (additional_service_id) references additional_service(additional_service_id),
        constraint fk_booking_hold_reservation foreign key (reservation_id) references reservation(reservation_id),
        constraint check_booking_hold_status check (hold_status in ('active', 'converted', 'expired', 'released', 'failed')),
        constraint check_booking_hold_service_price check (service_price >= 0),
        constraint check_booking_hold_total_amount check (total_amount > 0),
        constraint check_booking_hold_additional_price check (
            (additional_service_id is null and additional_service_price is null)
            or (additional_service_id is not null and additional_service_price is not null and additional_service_price >= 0)
        ),
        constraint check_booking_hold_total check (total_amount = service_price + coalesce(additional_service_price, 0))
);

create index if not exists idx_booking_hold_expiration
        on booking_hold (expires_at) where hold_status = 'active';
create index if not exists idx_booking_hold_slot_status_expiry
        on booking_hold (slot_id, hold_status, expires_at);
create index if not exists idx_booking_hold_user_status
        on booking_hold (user_id, hold_status);

create table if not exists shop_notice(
        notice_id serial primary key,
        admin_id int not null,
        title varchar(255) not null,
        message text not null,
        is_active boolean default true not null,
        start_date date,
        end_date date,
        created_at timestamp default current_timestamp not null,

        constraint fk_notice_admin foreign key (admin_id) references admin (admin_id)
);
