-- MySQL 5.7+. Run once before deploying the notification worker.
-- No sender existed before this migration. Retire the old backlog so historical
-- appointment messages are not delivered unexpectedly when the worker starts.
UPDATE notification_events
SET status='canceled', last_error='Retired before notification delivery activation'
WHERE status IN ('queued','sending');

ALTER TABLE notification_events
  MODIFY status ENUM('queued','sending','sent','delivered','failed','canceled','needs_review') NOT NULL DEFAULT 'queued',
  ADD COLUMN next_attempt_at DATETIME NULL AFTER scheduled_at,
  ADD COLUMN leased_until DATETIME NULL AFTER next_attempt_at,
  ADD COLUMN lease_token CHAR(32) NULL AFTER leased_until,
  ADD INDEX ix_notification_due (status,next_attempt_at,scheduled_at);
