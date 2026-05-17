<?php
class MapDataService
{
    private $flightRepository;
    private $statusBlockRepository;
    private $feoRepository;
    private $statusColors;
    private $statusNames;

    public function __construct($flightRepository, $statusBlockRepository, $feoRepository, $statusColors, $statusNames)
    {
        $this->flightRepository = $flightRepository;
        $this->statusBlockRepository = $statusBlockRepository;
        $this->feoRepository = $feoRepository;
        $this->statusColors = $statusColors;
        $this->statusNames = $statusNames;
    }

    public function build()
    {
        $flightStatuses = $this->getActualStatuses();
        $availableZayavki = $this->getAvailableZayavkiIds();
        $points = $this->feoRepository->fetchPointsWithCoords();
        if (!is_array($points)) {
            $points = [];
        }

        $filteredPoints = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $zayavkaId = isset($point['zayavka_id']) ? trim((string)$point['zayavka_id']) : '';
            if ($zayavkaId === '') {
                continue;
            }
            $point['zayavka_id'] = $zayavkaId;
            $point['mass_netto'] = isset($point['mass_netto']) ? (float)$point['mass_netto'] : 0;
            $point['mno_sh'] = $point['mno_sh'] ?? null;
            $point['mno_d'] = $point['mno_d'] ?? null;
            $point['naim_oo_gruzootpravitel'] = mapNormalizeText((string)($point['naim_oo_gruzootpravitel'] ?? ''));
            $point['mno_adres_pogruzki'] = mapNormalizeText((string)($point['mno_adres_pogruzki'] ?? ''));
            $point['zakaz_s_ot_status'] = mapNormalizeText((string)($point['zakaz_s_ot_status'] ?? ''));
            $inFlight = isset($flightStatuses[$point['zayavka_id']]);
            $isAvailable = isset($availableZayavki[$point['zayavka_id']]);
            if (!$inFlight && !$isAvailable) {
                continue;
            }

            $point['flight_status'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['status'] : null;
            $point['flight_id'] = $inFlight ? $flightStatuses[$point['zayavka_id']]['flight_id'] : null;

            if ($inFlight) {
                $point['marker_color'] = $flightStatuses[$point['zayavka_id']]['color'];
                $point['in_flight'] = true;
                $point['custom_layer'] = null;
                $point['custom_color'] = null;
            } else {
                $point['in_flight'] = false;
                $data = $availableZayavki[$point['zayavka_id']];
                if ($data['layer_name'] && $data['color']) {
                    $point['marker_color'] = $data['color'];
                    $point['custom_layer'] = $data['layer_name'];
                    $point['custom_color'] = $data['color'];
                } else {
                    $point['marker_color'] = '#000';
                    $point['custom_layer'] = null;
                    $point['custom_color'] = null;
                }
            }

            $filteredPoints[] = $point;
        }

        $groupedPoints = [];
        $processed = [];
        foreach ($filteredPoints as $i => $p) {
            if (isset($processed[$i])) {
                continue;
            }

            $key = "{$p['mno_sh']}|{$p['mno_d']}" . ($p['in_flight'] ? "|f|{$p['flight_status']}|{$p['flight_id']}" : "|n|" . ($p['custom_layer'] ?? 'def'));
            $groupedPoints[$key] = [
                'mno_sh' => $p['mno_sh'],
                'mno_d' => $p['mno_d'],
                'mno_adres_pogruzki' => $p['mno_adres_pogruzki'],
                'naim_oo_gruzootpravitel' => $p['naim_oo_gruzootpravitel'],
                'flight_status' => $p['flight_status'],
                'flight_id' => $p['flight_id'],
                'marker_color' => $p['marker_color'],
                'in_flight' => $p['in_flight'],
                'custom_layer' => $p['custom_layer'],
                'custom_color' => $p['custom_color'],
                'points' => [$p],
                'total_mass' => $p['mass_netto'],
                'count' => 1,
                'group_key' => $key,
            ];

            $processed[$i] = $key;

            foreach ($filteredPoints as $j => $o) {
                if ($j <= $i || isset($processed[$j])) {
                    continue;
                }

                if (canMergePoints($p, $o)) {
                    $groupedPoints[$key]['points'][] = $o;
                    $groupedPoints[$key]['total_mass'] += $o['mass_netto'];
                    $groupedPoints[$key]['count']++;
                    $processed[$j] = $key;
                }
            }
        }

        $processedGroups = array_values($groupedPoints);
        $coordMap = [];
        foreach ($processedGroups as $idx => $g) {
            $coordMap["{$g['mno_sh']}|{$g['mno_d']}"][] = $idx;
        }
        foreach ($coordMap as $arr) {
            foreach ($arr as $i => $idx) {
                $processedGroups[$idx]['base_index'] = $i;
            }
        }

        $flightStatusList = [];
        $customLayers = [];
        $hasDefault = false;
        foreach ($processedGroups as $g) {
            if ($g['in_flight']) {
                if (!empty($g['flight_status'])) {
                    $flightStatusList[$g['flight_status']] = true;
                }
            } else {
                if ($g['custom_layer']) {
                    $customLayers[$g['custom_layer']] = [
                        'name' => $g['custom_layer'],
                        'color' => $g['custom_color'],
                    ];
                } else {
                    $hasDefault = true;
                }
            }
        }

        $flightStatusList = array_intersect_key(array_flip(array_keys($this->statusNames)), $flightStatusList);

        return [
            'processedGroups' => $processedGroups,
            'flightStatusList' => $flightStatusList,
            'customLayers' => $customLayers,
            'hasDefault' => $hasDefault,
        ];
    }

    private function getActualStatuses()
    {
        $rows = $this->flightRepository->fetchStatusesForActual();
        if (!is_array($rows)) {
            $rows = [];
        }
        $statuses = [];
        $processed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = $row['status'] ?? null;
            $flightId = $row['id'] ?? null;
            foreach (explode(',', (string)($row['zayavki_ids'] ?? '')) as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }
                if (!isset($processed[$id])) {
                    $statuses[$id] = [
                        'status' => $status,
                        'color' => $this->statusColors[$status] ?? '#000',
                        'flight_id' => $flightId,
                    ];
                    $processed[$id] = true;
                }
            }
        }

        return $statuses;
    }

    private function getAvailableZayavkiIds()
    {
        $rows = $this->statusBlockRepository->fetchAvailableBlocks();
        if (!is_array($rows)) {
            $rows = [];
        }
        $available = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comment = $row['comment'] ?? '';
            $layer = null;
            $color = null;

            if (strpos($comment, '#') !== false) {
                $pos = strpos($comment, '#');
                $name = trim(substr($comment, 0, $pos));
                $col = trim(substr($comment, $pos));
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $col) || preg_match('/^#[0-9A-Fa-f]{3}$/', $col)) {
                    $layer = $name;
                    $color = $col;
                }
            }

            foreach (explode(',', (string)($row['zayavki_ids'] ?? '')) as $id) {
                $id = trim($id);
                if ($id) {
                    $available[$id] = ['layer_name' => $layer, 'color' => $color];
                }
            }
        }

        return $available;
    }
}
