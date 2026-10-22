USE halcones;

INSERT IGNORE INTO allowed_status_transitions (entity_type, from_status, to_status)
VALUES ('SERVICE', 'ARRIVED', 'CANCELLED');