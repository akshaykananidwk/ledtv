-- 2.6 panels (docs/modules/panels.md): deleting a customer from the Super Admin console.
-- Invoices are kept for accounting: invoices.hotel_id becomes NULL when the customer is deleted and
-- customer_name keeps the name shown on the invoice list (Hotels::delete fills it before deleting).
ALTER TABLE invoices DROP FOREIGN KEY fk_invoices_hotel;
ALTER TABLE invoices MODIFY hotel_id INT UNSIGNED NULL;
ALTER TABLE invoices ADD COLUMN customer_name VARCHAR(120) NULL AFTER hotel_id;
ALTER TABLE invoices ADD CONSTRAINT fk_invoices_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL;
-- §50 deletion policy: archive (soft delete) is the default action; archived customers are suspended, hidden from
-- the default customers list and restorable; "Delete permanently" stays a super admin action.
ALTER TABLE hotels ADD COLUMN archived_at DATETIME NULL AFTER suspend_reason;
