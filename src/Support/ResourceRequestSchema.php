<?php
namespace App\Support;

use App\Core\Database;

final class ResourceRequestSchema {
    public static function ready(): bool {
        try { Database::getInstance()->query('SELECT intake_id FROM disaster_resource_intakes LIMIT 0'); return true; }
        catch (\PDOException $e) { return false; }
    }

    public static function migrate(): void {
        $db=Database::getInstance();
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite' || getenv('DB_DRIVER')==='mysql') {
            throw new \RuntimeException('This migration is verified for explicitly configured SQLite only. No MySQL fallback migration is permitted.');
        }
        $db->beginTransaction();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS disaster_resource_intakes (
                intake_id INTEGER PRIMARY KEY AUTOINCREMENT,
                reference_no TEXT NOT NULL UNIQUE,
                owner_id INTEGER NOT NULL REFERENCES users(user_id),
                disaster_request_id INTEGER UNIQUE REFERENCES disaster_request_form(disaster_request_id),
                creation_key TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL CHECK(state IN ('Draft','Submitted','Approved','Rejected','Partially Allocated','Allocated','Completed')),
                payload_json TEXT NOT NULL,
                line_ids_json TEXT NOT NULL DEFAULT '{}',
                revision INTEGER NOT NULL DEFAULT 1 CHECK(revision>0),
                approved_priority TEXT CHECK(approved_priority IN ('Urgent','High','Medium','Low')),
                reviewed_by INTEGER REFERENCES users(user_id), reviewed_at TEXT, review_notes TEXT,
                ml_status TEXT NOT NULL DEFAULT 'Not requested' CHECK(ml_status IN ('Not requested','Pending','Available','Unavailable')),
                ml_result_json TEXT, ml_attempted_at TEXT,
                submitted_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS disaster_resource_history (
                history_id INTEGER PRIMARY KEY AUTOINCREMENT,
                intake_id INTEGER NOT NULL REFERENCES disaster_resource_intakes(intake_id),
                actor_id INTEGER NOT NULL REFERENCES users(user_id),
                action TEXT NOT NULL, previous_state TEXT NOT NULL, new_state TEXT NOT NULL,
                notes TEXT NOT NULL, details_json TEXT NOT NULL DEFAULT '{}',
                operation_key TEXT UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec('CREATE INDEX IF NOT EXISTS idx_resource_intakes_owner_state ON disaster_resource_intakes(owner_id,state)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_resource_history_intake ON disaster_resource_history(intake_id,history_id)');
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
}
