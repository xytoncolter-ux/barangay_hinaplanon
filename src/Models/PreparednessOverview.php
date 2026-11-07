<?php
namespace App\Models;

use App\Core\Database;
use PDO;

/** Read-only projections. No enrolment, assessment-cache replacement, or stock updates. */
final class PreparednessOverview {
    public static function historyFingerprint(array $row): string {
        $keys = ['record_id','disaster_date','disaster_type','affected_barangay','affected_purok',
            'severity_level','purok_risk_level','is_urgent','affected_households','affected_population','data_source','is_synthetic','is_verified'];
        $values = [];
        foreach ($keys as $key) $values[$key] = (string)($row[$key] ?? '');
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function trustedHistory(array $row, ?array $reconciled = null): bool {
        foreach (['affected_households','affected_population'] as $key) {
            if (!isset($row[$key]) || !is_numeric($row[$key]) || !is_finite((float)$row[$key]) || $row[$key] < 0) return false;
        }
        foreach (['disaster_type','severity_level','purok_risk_level','affected_purok'] as $key) {
            if (!isset($row[$key]) || trim((string)$row[$key]) === '') return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($row['disaster_date'] ?? ''));
        if (!$date || $date->format('Y-m-d') !== $row['disaster_date'] || $row['disaster_date'] > date('Y-m-d')) return false;
        if (!isset($row['is_urgent']) || !in_array((string)$row['is_urgent'], ['0','1'], true)) return false;
        if ($reconciled === null) $reconciled = (require __DIR__.'/../../config/preparedness.php')['reconciled_history'];
        $review = $reconciled[self::historyFingerprint($row)] ?? [];
        // This legacy import inferred population, severity, labels and quantities.
        return (int)$row['is_synthetic'] === 0 && (int)$row['is_verified'] === 1
            && trim((string)$row['data_source']) !== ''
            && !empty($review['reviewer']) && !empty($review['source_reference'])
            && $row['data_source'] !== 'TS Basyang Official BDRRMC Records (ECOWEB / CARD NGO Archive)';
    }

    public static function publicHistory(array $row): bool {
        $reviews = (require __DIR__.'/../../config/preparedness.php')['reconciled_history'];
        return self::trustedHistory($row, $reviews)
            && ($reviews[self::historyFingerprint($row)]['public_release'] ?? false) === true;
    }

    public static function load(string $role, int $userId): array {
        if (!in_array($role, ['Resident', 'Responder', 'Barangay Head', 'ICDRRMO'], true)) {
            throw new \InvalidArgumentException('Unauthorized preparedness role');
        }
        $db = Database::getInstance();
        $admin = in_array($role, ['Barangay Head', 'ICDRRMO'], true);
        $puroks = $db->query("SELECT p.* FROM purok p JOIN barangay b ON b.barangay_id=p.barangay_id
            WHERE LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')
            AND p.deleted_at IS NULL AND b.deleted_at IS NULL ORDER BY p.purok_id")->fetchAll(PDO::FETCH_ASSOC);
        $history = $db->query("SELECT * FROM historical_disaster_records
            WHERE LOWER(TRIM(affected_barangay)) IN ('hinaplanon','barangay hinaplanon')
            AND is_synthetic=0 ORDER BY disaster_date DESC,record_id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $trusted = array_values(array_filter($history, [self::class, 'trustedHistory']));
        $events = $db->query("SELECT d.disaster_request_id,d.purok_id,d.disaster_type,d.weather_name,
            d.disaster_date,d.status,d.severity_level,d.is_urgent,d.affected_households,d.affected_population,
            d.verified_at,p.purok_name,p.risk_classification
            FROM disaster_request_form d JOIN purok p ON p.purok_id=d.purok_id
            JOIN barangay b ON b.barangay_id=d.barangay_id AND b.barangay_id=p.barangay_id
            WHERE d.deleted_at IS NULL AND p.deleted_at IS NULL
            AND LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')
            AND d.verified_at IS NOT NULL AND d.verified_by_user_id IS NOT NULL
            ORDER BY d.disaster_date DESC,d.disaster_request_id DESC")->fetchAll(PDO::FETCH_ASSOC);
        // No public-release flag exists for requests, assessments or allocation records.
        $result = ['puroks' => $role === 'Resident' ? [] : $puroks,
            'history' => $role === 'Resident' ? array_values(array_filter($trusted, [self::class,'publicHistory'])) : $history,
            'trustedHistory' => $role === 'Resident' ? [] : $trusted, 'events' => $role === 'Resident' ? [] : $events,
            'allocations' => [], 'inventory' => [], 'claims' => [], 'admin' => $admin];
        if ($admin) {
            $result['allocations'] = array_values(array_filter(Recommendation::all(), fn($r) => in_array((int)$r['purok_id'], array_column($puroks, 'purok_id'))));
            $result['inventory'] = $db->query("SELECT s.* FROM supply s JOIN barangay b ON b.barangay_id=s.barangay_id
                WHERE s.deleted_at IS NULL AND LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')
                ORDER BY s.supply_id")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($role === 'Responder') {
            $stmt = $db->prepare("SELECT r.recommendation_id,r.disaster_request_id,r.purok_id,r.recommendation_status,
                r.recommended_quantity,s.supply_name,s.unit_of_measure,p.purok_name
                FROM recommendation r JOIN disaster_request_form d ON d.disaster_request_id=r.disaster_request_id
                JOIN purok p ON p.purok_id=r.purok_id JOIN barangay b ON b.barangay_id=p.barangay_id
                LEFT JOIN supply s ON s.supply_id=r.recommended_supply_id
                WHERE d.assigned_to_user_id=? AND r.recommendation_status IN ('Approved','Modified')
                AND r.deleted_at IS NULL AND d.deleted_at IS NULL
                AND LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')");
            $stmt->execute([$userId]);
            $result['allocations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        // Select no names, contact details, remarks or other residents' information.
        $sql = "SELECT rb.beneficiary_id,rb.purok_id,rb.assistance_status,rb.allocated_quantity,
            rb.unit_of_measure,rb.claiming_date,rb.claiming_time,rb.claiming_location,rb.claimed_at,
            rb.distributed_at,s.supply_name,p.purok_name FROM relief_beneficiaries rb
            JOIN purok p ON p.purok_id=rb.purok_id JOIN barangay b ON b.barangay_id=p.barangay_id
            JOIN users u ON u.user_id=rb.user_id LEFT JOIN supply s ON s.supply_id=rb.allocated_supply_id
            WHERE u.deleted_at IS NULL AND LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')";
        if ($role === 'Resident') {
            $stmt = $db->prepare($sql . ' AND rb.user_id=?');
            $stmt->execute([$userId]);
            $result['claims'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($admin) {
            $result['claims'] = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
