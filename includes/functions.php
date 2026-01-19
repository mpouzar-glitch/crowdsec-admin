<?php

// PHP 8.4 null safety helpers
if (!function_exists('safe_strtotime')) {
    function safe_strtotime($datetime) {
        return $datetime ? strtotime($datetime) : time();
    }
}

if (!function_exists('safe_html')) {
    function safe_html($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function parseLookbackToMs($lookbackPeriod) {
    if (preg_match('/^(\d+)([hmd])$/', $lookbackPeriod, $match)) {
        $val = (int)$match[1];
        $unit = $match[2];
        
        switch ($unit) {
            case 'h': return $val * 60 * 60 * 1000;
            case 'd': return $val * 24 * 60 * 60 * 1000;
            case 'm': return $val * 60 * 1000;
        }
    }
    
    return 7 * 24 * 60 * 60 * 1000; // Default 7 days
}

function toDuration($timestampMs) {
    $now = time() * 1000;
    $diffMs = $now - $timestampMs;
    
    $hours = floor($diffMs / 3600000);
    $minutes = floor(($diffMs % 3600000) / 60000);
    $seconds = floor(($diffMs % 60000) / 1000);
    
    return "{$hours}h{$minutes}m{$seconds}s";
}

function parseGoDuration($str) {
    if (!$str) return 0;
    
    $totalMs = 0;
    if (preg_match_all('/(\d+)(h|m|s)/', $str, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $val = (int)$match[1];
            $unit = $match[2];
            
            switch ($unit) {
                case 'h': $totalMs += $val * 3600000; break;
                case 'm': $totalMs += $val * 60000; break;
                case 's': $totalMs += $val * 1000; break;
            }
        }
    }
    
    return $totalMs;
}

function formatDateTime($value, $fallback = '-') {
    if (!$value) {
        return $fallback;
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$timestamp) {
        return $fallback;
    }

    return date('d.m.Y H:i', $timestamp);
}

function formatTime($value, $fallback = '-') {
    if (!$value) {
        return $fallback;
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$timestamp) {
        return $fallback;
    }

    return date('H:i', $timestamp);
}

function formatAlertDuration($startedAt, $stoppedAt) {
    if (!$startedAt) {
        return '-';
    }

    $startLabel = formatTime($startedAt);
    if ($startLabel === '-') {
        return '-';
    }

    if (!$stoppedAt) {
        return "start: {$startLabel} trvání -";
    }

    $start = strtotime((string) $startedAt);
    $stop = strtotime((string) $stoppedAt);
    if (!$start || !$stop || $stop < $start) {
        return "start: {$startLabel} trvání -";
    }

    $minutes = (int) round(($stop - $start) / 60);
    if ($minutes <= 0) {
        $minutes = 1;
    }

    if ($minutes >= 60) {
        $hours = (int) max(1, round($minutes / 60));
        return "start: {$startLabel} trvání {$hours} hod";
    }

    return "start: {$startLabel} trvání {$minutes} min";
}

function formatAlertDurationLabel($startedAt, $stoppedAt) {
    if (!$startedAt || !$stoppedAt) {
        return '-';
    }

    $start = strtotime((string) $startedAt);
    $stop = strtotime((string) $stoppedAt);
    if (!$start || !$stop || $stop < $start) {
        return '-';
    }

    $minutes = (int) round(($stop - $start) / 60);
    if ($minutes <= 0) {
        $minutes = 1;
    }

    if ($minutes >= 60) {
        $hours = (int) max(1, round($minutes / 60));
        return "{$hours} hod";
    }

    return "{$minutes} min";
}

function buildPaginationPages($current, $total) {
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1];
    $start = max(2, $current - 2);
    $end = min($total - 1, $current + 2);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($end < $total - 1) {
        $pages[] = '...';
    }

    $pages[] = $total;
    return $pages;
}

function renderPagination(array $options = []) {
    $current = (int) ($options['current'] ?? 1);
    $total = (int) ($options['total'] ?? 1);
    $buildQuery = $options['buildQuery'] ?? null;
    $baseUrl = $options['baseUrl'] ?? '';
    $ariaLabel = $options['ariaLabel'] ?? 'Stránkování';
    $prevLabel = $options['prevLabel'] ?? 'Předchozí';
    $nextLabel = $options['nextLabel'] ?? 'Další';
    $prevIcon = $options['prevIcon'] ?? 'fa-chevron-left';
    $nextIcon = $options['nextIcon'] ?? 'fa-chevron-right';

    if ($total <= 1) {
        return '';
    }

    $buildHref = function ($pageNumber) use ($buildQuery, $baseUrl) {
        $query = '';
        if (is_callable($buildQuery)) {
            $query = $buildQuery(['page' => $pageNumber]);
        } else {
            $query = http_build_query(['page' => $pageNumber]);
        }

        if ($baseUrl === '') {
            return '?' . $query;
        }

        $base = rtrim($baseUrl, '?');
        return $base . '?' . $query;
    };

    $html = '<nav class="pagination" aria-label="' . htmlspecialchars($ariaLabel) . '">' . "\n";

    if ($current > 1) {
        $html .= '    <a class="pagination-link" href="' . htmlspecialchars($buildHref($current - 1)) . '">';
        $html .= '<i class="fas ' . htmlspecialchars($prevIcon) . '"></i> ' . htmlspecialchars($prevLabel) . '</a>' . "\n";
    } else {
        $html .= '    <span class="pagination-link disabled"><i class="fas ' . htmlspecialchars($prevIcon) . '"></i> ';
        $html .= htmlspecialchars($prevLabel) . '</span>' . "\n";
    }

    foreach (buildPaginationPages($current, $total) as $pageNumber) {
        if ($pageNumber === '...') {
            $html .= '    <span class="pagination-ellipsis">…</span>' . "\n";
            continue;
        }

        if ((int) $pageNumber === $current) {
            $html .= '    <span class="pagination-link active">' . htmlspecialchars((string) $pageNumber) . '</span>' . "\n";
            continue;
        }

        $html .= '    <a class="pagination-link" href="' . htmlspecialchars($buildHref($pageNumber)) . '">';
        $html .= htmlspecialchars((string) $pageNumber) . '</a>' . "\n";
    }

    if ($current < $total) {
        $html .= '    <a class="pagination-link" href="' . htmlspecialchars($buildHref($current + 1)) . '">';
        $html .= htmlspecialchars($nextLabel) . ' <i class="fas ' . htmlspecialchars($nextIcon) . '"></i></a>' . "\n";
    } else {
        $html .= '    <span class="pagination-link disabled">';
        $html .= htmlspecialchars($nextLabel) . ' <i class="fas ' . htmlspecialchars($nextIcon) . '"></i></span>' . "\n";
    }

    $html .= '</nav>' . "\n";
    return $html;
}

function renderMessagesTableHeader(array $options = []) {
    $sort = $options['sort'] ?? '';
    $buildSortLink = $options['buildSortLink'] ?? null;
    $getSortIcon = $options['getSortIcon'] ?? null;
    $columns = $options['columns'] ?? [
        'timestamp',
        'sender',
        'recipients',
        'subject',
        'action',
        'score',
        'size_bytes',
        'status',
        'ip_address',
        'hostname',
    ];

    $columnDefinitions = [
        'created_at' => [
            'label' => 'Čas',
            'class' => 'col-timestamp',
            'sort' => 'created_at',
        ],
        'started_at' => [
            'label' => 'Začátek',
            'class' => 'col-timestamp',
            'sort' => 'started_at',
        ],
        'stopped_at' => [
            'label' => 'Konec',
            'class' => 'col-timestamp',
            'sort' => 'stopped_at',
        ],
        'timestamp' => [
            'label' => __('time'),
            'class' => 'col-timestamp',
            'sort' => 'timestamp',
        ],
        'sender' => [
            'label' => __('msg_sender'),
            'class' => 'col-email',
            'sort' => 'sender',
        ],
        'recipients' => [
            'label' => __('msg_recipient'),
            'class' => 'col-email',
            'sort' => 'recipients',
        ],
        'subject' => [
            'label' => __('msg_subject'),
            'class' => 'col-subject',
            'sort' => 'subject',
        ],
        'action' => [
            'label' => __('action'),
            'class' => 'col-action',
            'sort' => 'action',
        ],
        'scenario' => [
            'label' => 'Scénář',
            'class' => 'col-scenario',
            'sort' => 'scenario',
        ],
        'score' => [
            'label' => __('msg_score'),
            'class' => 'col-score',
            'sort' => 'score',
        ],
        'size_bytes' => [
            'label' => __('msg_size'),
            'class' => 'col-size',
            'sort' => 'size_bytes',
        ],
        'size' => [
            'label' => __('size'),
            'class' => 'col-size',
            'sort' => 'size',
        ],
        'status' => [
            'label' => 'STATUS',
            'style' => 'width: 180px;',
        ],
        'source_ip' => [
            'label' => 'IP adresa',
            'class' => 'col-ip',
            'sort' => 'source_ip',
        ],
        'source_country' => [
            'label' => 'Země',
            'class' => 'col-country',
            'sort' => 'source_country',
        ],
        'events_count' => [
            'label' => 'Počet událostí',
            'class' => 'col-count',
            'sort' => 'events_count',
        ],
        'simulated' => [
            'label' => 'Simulované',
            'class' => 'col-boolean',
        ],
        'ip_address' => [
            'label' => __('ip_address'),
            'class' => 'col-ip',
            'sort' => 'ip_address',
        ],
        'id' => [
            'label' => 'ID',
            'class' => 'col-id',
        ],
        'type' => [
            'label' => 'Typ',
            'class' => 'col-type',
        ],
        'country' => [
            'label' => 'Země',
            'class' => 'col-country',
        ],
        'expiration' => [
            'label' => 'Expirace',
            'class' => 'col-expiration',
        ],
        'value' => [
            'label' => 'IP adresa',
            'class' => 'col-ip',
        ],
        'hostname' => [
            'label' => __('hostname'),
            'class' => 'col-hostname',
            'sort' => 'hostname',
        ],
        'actions' => [
            'label' => __('actions'),
            'style' => 'width: 150px;',
        ],
    ];

    $header = "<thead>\n    <tr>\n";

    foreach ($columns as $column) {
        $columnConfig = [];
        if (is_string($column)) {
            $columnConfig = $columnDefinitions[$column] ?? [];
            $columnConfig['key'] = $column;
        } elseif (is_array($column)) {
            $columnKey = $column['key'] ?? null;
            $columnConfig = array_merge($columnDefinitions[$columnKey] ?? [], $column);
            $columnConfig['key'] = $columnKey;
        }

        if (empty($columnConfig['key'])) {
            continue;
        }

        $label = $columnConfig['label'] ?? $columnConfig['key'];
        $class = $columnConfig['class'] ?? '';
        $style = $columnConfig['style'] ?? '';
        $sortKey = $columnConfig['sort'] ?? null;
        $sortable = $columnConfig['sortable'] ?? true;

        $attributes = '';
        if (!empty($class)) {
            $attributes .= ' class="' . htmlspecialchars($class) . '"';
        }
        if (!empty($style)) {
            $attributes .= ' style="' . htmlspecialchars($style) . '"';
        }

        if ($sortable && $sortKey && is_callable($buildSortLink) && is_callable($getSortIcon)) {
            $isActive = ($sort === $sortKey);
            $header .= "        <th{$attributes}>\n";
            $header .= "            <a class=\"sort-link " . ($isActive ? 'active' : '') . "\" href=\""
                . htmlspecialchars($buildSortLink($sortKey)) . "\">\n";
            $header .= "                " . htmlspecialchars($label) . "\n";
            $header .= "                <i class=\"fas " . htmlspecialchars($getSortIcon($sortKey)) . "\"></i>\n";
            $header .= "            </a>\n";
            $header .= "        </th>\n";
        } else {
            $header .= "        <th{$attributes}>" . htmlspecialchars($label) . "</th>\n";
        }
    }

    $header .= "    </tr>\n</thead>\n";

    return $header;
}

function renderFilterForm(array $options = []) {
    $method = strtolower($options['method'] ?? 'get');
    $action = $options['action'] ?? '';
    $class = $options['class'] ?? 'table-filters';
    $fields = $options['fields'] ?? [];
    $submitLabel = $options['submitLabel'] ?? 'Filtrovat';
    $resetLabel = $options['resetLabel'] ?? 'Vyčistit filtry';
    $resetUrl = $options['resetUrl'] ?? null;

    $attributes = ' method="' . htmlspecialchars($method) . '"';
    if ($action !== '') {
        $attributes .= ' action="' . htmlspecialchars($action) . '"';
    }
    if ($class !== '') {
        $attributes .= ' class="' . htmlspecialchars($class) . '"';
    }

    $html = '<form' . $attributes . '>' . "\n";

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $type = $field['type'] ?? 'text';
        $name = $field['name'] ?? '';
        if ($name === '') {
            continue;
        }

        if ($type === 'hidden') {
            $value = $field['value'] ?? '';
            $html .= '    <input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $value) . '">' . "\n";
            continue;
        }

        $id = $field['id'] ?? $name;
        $value = $field['value'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $label = $field['label'] ?? '';
        $labelHtml = $field['labelHtml'] ?? null;
        $groupClass = $field['groupClass'] ?? 'filter-group';
        $inputClass = $field['class'] ?? '';
        $datalist = $field['datalist'] ?? [];

        if ($type === 'checkbox') {
            $groupClass .= ' checkbox';
        }

        $html .= '    <div class="' . htmlspecialchars($groupClass) . '">' . "\n";
        if ($labelHtml !== null) {
            $html .= '        <label for="' . htmlspecialchars($id) . '">' . $labelHtml . '</label>' . "\n";
        } elseif ($label !== '') {
            $html .= '        <label for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>' . "\n";
        }

        if ($type === 'select') {
            $html .= '        <select id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"';
            if ($inputClass !== '') {
                $html .= ' class="' . htmlspecialchars($inputClass) . '"';
            }
            $html .= '>' . "\n";
            foreach (($field['options'] ?? []) as $optionValue => $optionLabel) {
                $optionData = $optionLabel;
                if (is_array($optionLabel)) {
                    $optionData = $optionLabel['label'] ?? $optionLabel['value'] ?? $optionValue;
                    $optionValue = $optionLabel['value'] ?? $optionValue;
                }
                $selected = ((string) $optionValue === (string) $value) ? ' selected' : '';
                $html .= '            <option value="' . htmlspecialchars((string) $optionValue) . '"' . $selected . '>';
                $html .= htmlspecialchars((string) $optionData) . '</option>' . "\n";
            }
            $html .= "        </select>\n";
        } else {
            $html .= '        <input type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"';
            if ($placeholder !== '') {
                $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
            }
            if ($inputClass !== '') {
                $html .= ' class="' . htmlspecialchars($inputClass) . '"';
            }
            if ($type === 'checkbox') {
                $checkboxValue = $field['value'] ?? '1';
                $html .= ' value="' . htmlspecialchars((string) $checkboxValue) . '"';
                $checked = !empty($field['checked']) ? ' checked' : '';
                $html .= $checked;
            } else {
                $html .= ' value="' . htmlspecialchars((string) $value) . '"';
            }
            if (!empty($datalist)) {
                $listId = $field['listId'] ?? ($id . 'List');
                $html .= ' list="' . htmlspecialchars($listId) . '"';
            }
            $html .= '>' . "\n";

            if (!empty($datalist)) {
                $listId = $field['listId'] ?? ($id . 'List');
                $html .= '        <datalist id="' . htmlspecialchars($listId) . '">' . "\n";
                foreach ($datalist as $item) {
                    $html .= '            <option value="' . htmlspecialchars((string) $item) . '"></option>' . "\n";
                }
                $html .= "        </datalist>\n";
            }
        }

        $html .= "    </div>\n";
    }

    $html .= '    <div class="filter-actions">' . "\n";
    $html .= '        <button class="btn btn-ghost" type="submit">' . htmlspecialchars($submitLabel) . '</button>' . "\n";
    if ($resetUrl !== null) {
        $html .= '        <a class="btn btn-ghost" href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetLabel) . '</a>' . "\n";
    }
    $html .= "    </div>\n";
    $html .= "</form>\n";

    return $html;
}

function setFlashMessage($type, $message) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $message;
}

function getAlertTarget($alert) {
    if (!$alert) return "Unknown";
    
    // Try to find target in events
    if (isset($alert['events']) && is_array($alert['events'])) {
        foreach ($alert['events'] as $event) {
            if (isset($event['meta']) && is_array($event['meta'])) {
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'target_fqdn' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
                
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'target_host' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
                
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'service' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
            }
        }
    }
    
    return $alert['machine_alias'] ?? $alert['machine_id'] ?? "Unknown";
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function loadEnv() {
    $env = [];
    $envFile = __DIR__ . '/../.env';
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $env[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
    
    return $env;
}

$appEnv = loadEnv();
date_default_timezone_set($appEnv['TIMEZONE'] ?? 'Europe/Prague');
