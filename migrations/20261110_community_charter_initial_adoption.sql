-- Enable the first collective adoption of the seeded foundation charter.
-- Existing amendment records remain amendments through the default case_type.
ALTER TABLE community_charter_amendments
    MODIFY base_charter_version_id BIGINT UNSIGNED NULL,
    ADD COLUMN case_type ENUM('amendment','initial_adoption') NOT NULL DEFAULT 'amendment' AFTER id,
    ADD KEY idx_charter_case_type_status (case_type, status, created_at);
