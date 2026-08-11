<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| ANPASSEN
|--------------------------------------------------------------------------
*/

$CLAIMS_TABLE = 'claims';
$MEASURES_TABLE = 'claim_actions';

/*
  Workbench-Service-API:
  Das ist die Datei im Workbench-Projekt:
  /service/api/create_from_8d_measure.php
*/
$WORKBENCH_ENDPOINT = 'https://your-workbench.de/service/api/create_from_8d_measure.php';

/*
  Muss exakt identisch sein mit:
  const WORKBENCH_8D_API_TOKEN = '...';
  in /service/api/create_from_8d_measure.php
*/
$WORKBENCH_TOKEN = 'CHANGE_ME_8D_TO_WORKBENCH_SECRET_2026';

/*
|--------------------------------------------------------------------------
| Ausgabe
|--------------------------------------------------------------------------
*/

function out(bool $ok, array $data = [], int $http = 200): void {
    http_response_code($http);
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
/*
|--------------------------------------------------------------------------
| DB laden — angepasst für dein 8D-Projekt
|--------------------------------------------------------------------------
| Deine db.php liegt im Hauptordner und stellt die Funktion pdo() bereit.
*/

$dbFiles = [
    __DIR__ . '/db.php',
    __DIR__ . '/api/_db.php',
    __DIR__ . '/_db.php',
    __DIR__ . '/inc/db.php',
    __DIR__ . '/inc/_db.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/config.php',
];

$dbLoaded = false;
$loadedDbFile = null;

foreach ($dbFiles as $file) {
    if (is_file($file)) {
        require_once $file;
        $dbLoaded = true;
        $loadedDbFile = $file;
        break;
    }
}

if (!$dbLoaded) {
    out(false, [
        'error' => 'Keine 8D-Datenbankdatei gefunden.',
        'searched_files' => $dbFiles
    ], 500);
}

function pdo_conn(): PDO {
    global $pdo, $db, $conn;

    /*
      Dein 8D-Projekt nutzt diese Funktion aus db.php:
      function pdo(): PDO
    */
    if (function_exists('pdo')) {
        $connection = pdo();

        if ($connection instanceof PDO) {
            return $connection;
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($db) && $db instanceof PDO) {
        return $db;
    }

    if (isset($conn) && $conn instanceof PDO) {
        return $conn;
    }

    throw new RuntimeException('Keine PDO-Datenbankverbindung im 8D-Projekt gefunden. Geladene Datei: ' . (string)($GLOBALS['loadedDbFile'] ?? 'unbekannt'));
}

function body_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");

    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function pick_column(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $column) {
        if (column_exists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function first_value(array $row, array $columns, string $fallback = ''): string {
    foreach ($columns as $column) {
        if (array_key_exists($column, $row) && trim((string)$row[$column]) !== '') {
            return trim((string)$row[$column]);
        }
    }

    return $fallback;
}

function current_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    return $scheme . '://' . $host;
}

function absolute_url(string $path): string {
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    if (str_starts_with($path, '/')) {
        return current_base_url() . $path;
    }

    return current_base_url() . '/' . ltrim($path, '/');
}

function collect_claim_images(PDO $pdo, int $claimId): array {
    $imageTables = [
        'claim_images',
        'claims_images',
        'claim_files',
        'claim_attachments',
        'claim_uploads',
        'claim_photos'
    ];

    $images = [];

    foreach ($imageTables as $table) {
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $claimIdCol = pick_column($pdo, $table, ['claim_id', 'reklamation_id']);
        $pathCol = pick_column($pdo, $table, ['file_path', 'image_path', 'path', 'url']);
        $nameCol = pick_column($pdo, $table, ['file_name', 'filename', 'original_name', 'name', 'title']);

        if (!$claimIdCol || !$pathCol) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM `$table`
            WHERE `$claimIdCol` = ?
        ");

        $stmt->execute([$claimId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $path = trim((string)($row[$pathCol] ?? ''));

            if ($path === '') {
                continue;
            }

            $url = absolute_url($path);

            $name = $nameCol && !empty($row[$nameCol])
                ? (string)$row[$nameCol]
                : basename(parse_url($url, PHP_URL_PATH) ?: '8d_bild.jpg');

            $images[] = [
                'file_name' => $name,
                'file_path' => $url
            ];
        }

        break;
    }

    return $images;
}

function post_json(string $url, array $payload, string $token): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Workbench-Token: ' . $token,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('cURL-Fehler: ' . $error);
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Workbench liefert kein JSON. Antwort beginnt mit: ' . substr((string)$response, 0, 250));
        }

        if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
            throw new RuntimeException((string)($decoded['error'] ?? ('HTTP ' . $status)));
        }

        return $decoded;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' =>
                "Content-Type: application/json\r\n" .
                "X-Workbench-Token: {$token}\r\n",
            'content' => $json,
            'timeout' => 30,
            'ignore_errors' => true,
        ]
    ]);

    $response = file_get_contents($url, false, $context);
    $decoded = json_decode((string)$response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Workbench liefert kein JSON. Antwort beginnt mit: ' . substr((string)$response, 0, 250));
    }

    if (empty($decoded['ok'])) {
        throw new RuntimeException((string)($decoded['error'] ?? 'Workbench-API Fehler.'));
    }

    return $decoded;
}

function normalize_service_url(array $workbenchResult, string $workbenchEndpoint): array {
    $serviceOrderId = 0;

    if (isset($workbenchResult['service_order_id'])) {
        $serviceOrderId = (int)$workbenchResult['service_order_id'];
    } elseif (isset($workbenchResult['id'])) {
        $serviceOrderId = (int)$workbenchResult['id'];
    }

    $serviceUrl = '';

    if (!empty($workbenchResult['service_url'])) {
        $serviceUrl = (string)$workbenchResult['service_url'];
    } elseif (!empty($workbenchResult['url'])) {
        $serviceUrl = (string)$workbenchResult['url'];
    } elseif (!empty($workbenchResult['redirect_url'])) {
        $serviceUrl = (string)$workbenchResult['redirect_url'];
    }

    if ($serviceUrl === '' && $serviceOrderId > 0) {
        $serviceUrl = '/service/view.php?id=' . $serviceOrderId;
    }

    if ($serviceUrl !== '' && str_starts_with($serviceUrl, '/')) {
        $endpointParts = parse_url($workbenchEndpoint);

        $scheme = $endpointParts['scheme'] ?? 'https';
        $host = $endpointParts['host'] ?? '';

        if ($host !== '') {
            $serviceUrl = $scheme . '://' . $host . $serviceUrl;
        }
    }

    return [
        'service_order_id' => $serviceOrderId,
        'service_url' => $serviceUrl
    ];
}

/*
|--------------------------------------------------------------------------
| Hauptlogik
|--------------------------------------------------------------------------
*/

try {
    $pdo = pdo_conn();

    if (!table_exists($pdo, $CLAIMS_TABLE)) {
        out(false, [
            'error' => 'Claims-Tabelle nicht gefunden: ' . $CLAIMS_TABLE
        ], 500);
    }

    if (!table_exists($pdo, $MEASURES_TABLE)) {
        out(false, [
            'error' => 'Maßnahmen-Tabelle nicht gefunden: ' . $MEASURES_TABLE
        ], 500);
    }

    $data = body_json();
    $measureId = (int)($data['measure_id'] ?? 0);

    if ($measureId <= 0) {
        out(false, [
            'error' => 'Maßnahmen-ID fehlt.'
        ], 400);
    }

    $measureIdCol = pick_column($pdo, $MEASURES_TABLE, ['id']);
    $measureClaimCol = pick_column($pdo, $MEASURES_TABLE, ['claim_id', 'reklamation_id']);

    if (!$measureIdCol || !$measureClaimCol) {
        out(false, [
            'error' => 'Maßnahmentabelle braucht id und claim_id/reklamation_id.'
        ], 500);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM `$MEASURES_TABLE`
        WHERE `$measureIdCol` = ?
        LIMIT 1
    ");

    $stmt->execute([$measureId]);
    $measure = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$measure) {
        out(false, [
            'error' => 'Maßnahme nicht gefunden.'
        ], 404);
    }

    $claimId = (int)$measure[$measureClaimCol];

    $claimIdCol = pick_column($pdo, $CLAIMS_TABLE, ['id']);

    if (!$claimIdCol) {
        out(false, [
            'error' => 'Claims-Tabelle braucht eine id-Spalte.'
        ], 500);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM `$CLAIMS_TABLE`
        WHERE `$claimIdCol` = ?
        LIMIT 1
    ");

    $stmt->execute([$claimId]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        out(false, [
            'error' => 'Reklamation zur Maßnahme nicht gefunden.'
        ], 404);
    }

    $claimNumber = first_value($claim, [
        'claim_no',
        'claim_number',
        'number',
        'reklamationsnummer',
        'ref_no'
    ], '8D-' . $claimId);

    $claimTitle = first_value($claim, [
        'title',
        'subject',
        'problem',
        'beschreibung',
        'description'
    ], '8D-Reklamation');

    $measureTitle = first_value($measure, [
        'title',
        'name',
        'measure_title',
        'action_title'
    ], '8D-Maßnahme');

    $measureText = first_value($measure, [
        'description',
        'measure',
        'measure_text',
        'action',
        'task',
        'note',
        'comment',
        'beschreibung'
    ], '');

    $sourceUrl = absolute_url('/claim_view.php?id=' . $claimId);
    $images = collect_claim_images($pdo, $claimId);

    $payload = [
        'external_measure_id' => (string)$measureId,
        'claim_id' => (string)$claimId,
        'claim_number' => $claimNumber,
        'claim_title' => $claimTitle,
        'measure_title' => $measureTitle,
        'measure_text' => $measureText,
        'customer_name' => first_value($claim, ['customer_name', 'kunde', 'supplier_name', 'lieferant', 'company'], ''),
        'customer_address' => first_value($claim, ['customer_address', 'adresse', 'address'], ''),
        'contact_name' => first_value($claim, ['contact_name', 'ansprechpartner'], ''),
        'contact_phone' => first_value($claim, ['contact_phone', 'telefon', 'phone'], ''),
        'source_url' => $sourceUrl,
        'images' => $images,
    ];

    $workbenchResult = post_json($WORKBENCH_ENDPOINT, $payload, $WORKBENCH_TOKEN);
    $normalized = normalize_service_url($workbenchResult, $WORKBENCH_ENDPOINT);

    if ($normalized['service_url'] === '') {
        out(false, [
            'error' => 'Workbench hat keine Service-URL zurückgegeben.',
            'workbench_response' => $workbenchResult
        ], 500);
    }

    out(true, [
        'already_exists' => !empty($workbenchResult['already_exists']),
        'service_order_id' => $normalized['service_order_id'],
        'order_no' => $workbenchResult['order_no'] ?? null,
        'copied_images' => (int)($workbenchResult['copied_images'] ?? 0),
        'url' => $normalized['service_url'],
        'service_url' => $normalized['service_url'],
    ]);

} catch (Throwable $e) {
    out(false, [
        'error' => $e->getMessage()
    ], 500);
}