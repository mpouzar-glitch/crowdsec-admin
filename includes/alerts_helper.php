<?php

function buildAlertsWhereClause(array $filters, array &$params): string {
    $clauses = [];

    $ip = trim((string) ($filters['ip'] ?? ''));
    $scenario = trim((string) ($filters['scenario'] ?? ''));
    $country = trim((string) ($filters['country'] ?? ''));
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    $simulated = (string) ($filters['simulated'] ?? '');

    if ($ip !== '') {
        $clauses[] = 'source_ip = :ip';
        $params[':ip'] = $ip;
    }

    if ($scenario !== '') {
        $clauses[] = 'scenario LIKE :scenario';
        $params[':scenario'] = '%' . $scenario . '%';
    }

    if ($country !== '') {
        $clauses[] = 'source_country = :country';
        $params[':country'] = $country;
    }

    if ($dateFrom !== '') {
        $clauses[] = 'created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $clauses[] = 'created_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    if ($simulated !== '') {
        $clauses[] = 'simulated = :simulated';
        $params[':simulated'] = (int) $simulated;
    }

    if (empty($clauses)) {
        return '';
    }

    return 'WHERE ' . implode(' AND ', $clauses);
}

function buildAlertsSort(string $sort, string $sortDir, array $sortableColumns): array {
    if (!isset($sortableColumns[$sort])) {
        $sort = 'created_at';
    }

    $sortDir = strtolower($sortDir);
    $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

    return [
        'sort' => $sort,
        'dir' => $sortDir,
        'order_by' => $sortableColumns[$sort] . ' ' . strtoupper($sortDir)
    ];
}
