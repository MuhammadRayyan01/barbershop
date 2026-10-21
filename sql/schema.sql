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

create table if not exists service(
        service_id serial primary key,
        service_name varchar(255) not null,
        price decimal (10, 2) not null,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null
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
        is_available slot_status default 'available' not null
);

create type res_status as enum('active', 'inactive');
create table if not exists reservation(
        reservation_id serial primary key,
        user_id int not null,
        slot_id int not null,
        service_id int not null,
        additional_service_id int,
        reservation_status res_status default 'active' not null,
        created_at timestamp default current_timestamp not null,
        updated_at timestamp default current_timestamp not null,

        constraint fk_reservation_user foreign key (user_id) references users (user_id),
        constraint fk_reservation_slot foreign key (slot_id) references time_slot (slot_id),
        constraint fk_reservation_service foreign key (service_id) references service (service_id),
        constraint fk_reservation_additional foreign key (additional_service_id) references additional_service (additional_service_id)
);

create type pay_status as enum('pending','paid','verification pending','failed','refunded');
create table if not exists payment(
        payment_id serial primary key,
        reservation_id int unique not null,
        payment_type varchar(100) not null,
        payment_status pay_status default 'pending' not null,
        amount decimal(10, 2) not null,
        payment_proof_url varchar(255),
        transaction_reference varchar(255),
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

        constraint fk_invoice_reservation foreign key (reservation_id) references reservation (reservation_id)
);

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