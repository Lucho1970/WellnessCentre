-- Optional daily maintenance; run in the application's database after migration 005.
-- Bounded batches: repeat if 1000 rows were affected. No client/link/claim/audit deletion.
DELETE FROM customer_auth_challenges WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000;
DELETE FROM customer_rate_limits WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000;
DELETE FROM customer_sessions WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000;
