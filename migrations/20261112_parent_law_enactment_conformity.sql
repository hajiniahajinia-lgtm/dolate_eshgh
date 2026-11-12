-- Parent-law enactment starts a conformity review for active descendant-community laws.
ALTER TABLE community_law_conformity_cases
    MODIFY trigger_type ENUM('parent_changed','manual_review','parent_law_enacted')
    NOT NULL DEFAULT 'parent_changed';
