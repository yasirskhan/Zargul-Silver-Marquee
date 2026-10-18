-- HAFZATECH support records in VENDOR database only.
-- NOT venue deposits, contacts, guest lists, payroll, invoices or bank data.
CREATE TABLE IF NOT EXISTS vendor_support_tickets (
    id uuid PRIMARY KEY,
    ticket_number bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    installation_id uuid NOT NULL,
    client_ticket_id uuid NOT NULL,
    subject_ciphertext bytea NOT NULL CHECK (octet_length(subject_ciphertext) >= 16),
    content_sha256 char(64) NOT NULL,
    status text NOT NULL DEFAULT 'new'
        CHECK (status IN ('new','pending','in_progress','waiting_for_client',
                         'on_hold','resolved','closed','reopened')),
    assigned_staff_id uuid REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (customer_id,installation_id)
        REFERENCES registered_installations(customer_id,id) ON DELETE RESTRICT,
    UNIQUE (customer_id,installation_id,client_ticket_id),
    UNIQUE (customer_id,id)
);
CREATE INDEX IF NOT EXISTS vendor_support_tickets_by_customer
    ON vendor_support_tickets(customer_id,created_at DESC);
CREATE TABLE IF NOT EXISTS vendor_support_messages (
    id uuid PRIMARY KEY,
    ticket_id uuid NOT NULL,
    customer_id uuid NOT NULL REFERENCES vendor_customers(id) ON DELETE RESTRICT,
    source_message_id uuid NOT NULL,
    direction text NOT NULL CHECK (direction IN ('customer','staff')),
    author_staff_id uuid REFERENCES central_staff_users(id) ON DELETE RESTRICT,
    body_ciphertext bytea NOT NULL CHECK (octet_length(body_ciphertext) >= 16),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (ticket_id,source_message_id),
    FOREIGN KEY (customer_id,ticket_id) REFERENCES vendor_support_tickets(customer_id,id) ON DELETE RESTRICT,
    CHECK ((direction='customer' AND author_staff_id IS NULL)
        OR (direction='staff' AND author_staff_id IS NOT NULL))
);
