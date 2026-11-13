ALTER TABLE payment
    ADD COLUMN IF NOT EXISTS snap_token varchar(255);
