<?php
class StatusBlockRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchAvailableBlocks()
    {
        try {
            $stmt = $this->pdo->query("SELECT zayavki_ids, comment FROM status_blocks WHERE status_type = 'dostupno'");
            if (!$stmt) {
                mapError('StatusBlockRepository query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('StatusBlockRepository query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
