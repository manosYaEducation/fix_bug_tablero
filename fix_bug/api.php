<?php
/**
 * Kreative Bug/Fix Tracker — api.php
 * Backend PHP: maneja proyectos, sesiones de carga CSV, persistencia en data.json
 *
 * Endpoints:
 *   GET  ?action=list                  → Lista todos los proyectos
 *   GET  ?action=project&id=xxx        → Datos de un proyecto (con todas sus sesiones)
 *   POST ?action=upload                → Sube CSV, parsea y guarda sesión
 *   POST ?action=delete_session        → Elimina una sesión específica
 *   POST ?action=delete_project        → Elimina un proyecto completo
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

define('DATA_FILE', __DIR__ . '/data.json');

// ─── HELPERS ──────────────────────────────────────────────────────────────

function loadData(): array {
    if (!file_exists(DATA_FILE)) return ['projects' => []];
    $raw = file_get_contents(DATA_FILE);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['projects' => []];
}

function saveData(array $data): bool {
    return file_put_contents(
        DATA_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n','ç'=>'c'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

// ─── CSV PARSER ───────────────────────────────────────────────────────────

function parseCSV(string $content): array {
    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    // Manual parser to handle multiline quoted fields
    $rows = [];
    $currentRow = [];
    $currentCell = '';
    $inQuote = false;
    $len = strlen($content);

    for ($i = 0; $i < $len; $i++) {
        $ch = $content[$i];
        if ($ch === '"') {
            if ($inQuote && $i + 1 < $len && $content[$i + 1] === '"') {
                $currentCell .= '"';
                $i++;
            } else {
                $inQuote = !$inQuote;
            }
        } elseif ($ch === ',' && !$inQuote) {
            $currentRow[] = trim($currentCell);
            $currentCell = '';
        } elseif ($ch === "\n" && !$inQuote) {
            $currentRow[] = trim($currentCell);
            $currentCell = '';
            if (array_filter($currentRow, fn($c) => $c !== '')) {
                $rows[] = $currentRow;
            }
            $currentRow = [];
        } else {
            $currentCell .= $ch;
        }
    }
    // Last row
    $currentRow[] = trim($currentCell);
    if (array_filter($currentRow, fn($c) => $c !== '')) {
        $rows[] = $currentRow;
    }

    if (empty($rows)) return [];

    // Find header row
    $headerIdx = -1;
    foreach ($rows as $idx => $row) {
        $rowStr = strtolower(implode(',', $row));
        if (strpos($rowStr, 'bug') !== false || strpos($rowStr, 'descripci') !== false) {
            $headerIdx = $idx;
            break;
        }
    }
    if ($headerIdx === -1) return [];

    $headers = $rows[$headerIdx];

    // Column finder
    $findCol = function(array $names) use ($headers): int {
        foreach ($names as $name) {
            foreach ($headers as $i => $h) {
                if (mb_strtolower(trim($h)) === mb_strtolower(trim($name))) return $i;
            }
        }
        return -1;
    };

    $col = [
        'id'          => $findCol(['#', 'id', '1']),
        'type'        => $findCol(['bug/fix', 'tipo', 'type']),
        'date'        => $findCol(['fecha de reporte', 'fecha reporte']),
        'section'     => $findCol(['sección', 'seccion', 'section']),
        'desc'        => $findCol(['descripción', 'descripcion', 'description']),
        'task'        => $findCol(['tarea', 'task']),
        'by'          => $findCol(['detectado por', 'detectado']),
        'priority'    => $findCol(['prioridad', 'priority']),
        'statusT'     => $findCol(['estado tester', 'estado test']),
        'responsible' => $findCol(['responsable']),
        'statusR'     => $findCol(['estado responsable', 'estado resp']),
        'resDate'     => $findCol(['fecha de resolución', 'fecha resolución', 'fecha resolucion']),
        'comments'    => $findCol(['comentarios', 'comments']),
    ];

    $get = function(array $row, int $idx): string {
        return isset($row[$idx]) ? trim($row[$idx]) : '';
    };

    // Skip keywords that indicate non-data rows
    $metaKeywords = ['proyectos','tareas','urgente','importante','urgente (+)','no importante','poco importante','muy importante','(-)','(+)'];

    $items = [];
    $seenIds = [];

    for ($r = $headerIdx + 1; $r < count($rows); $r++) {
        $row = $rows[$r];

        // Skip empty
        if (empty(array_filter($row, fn($c) => $c !== ''))) continue;

        // Skip meta/legend rows
        $isMeta = false;
        foreach ($row as $cell) {
            if (in_array(strtolower(trim($cell)), $metaKeywords)) { $isMeta = true; break; }
        }
        if ($isMeta) continue;

        $desc = $get($row, $col['desc']);
        if (empty($desc)) continue;

        // Type normalization
        $rawType = $get($row, $col['type']);
        $type = ucfirst(strtolower($rawType));
        if (!in_array($type, ['Bug', 'Fix'])) $type = 'Fix';

        // ID
        $rawId = $get($row, $col['id']);
        $id = is_numeric($rawId) ? (int)$rawId : null;
        if ($id !== null) {
            if (in_array($id, $seenIds)) continue;
            $seenIds[] = $id;
        }

        $items[] = [
            'id'          => $id,
            'type'        => $type,
            'date'        => $get($row, $col['date']),
            'section'     => $get($row, $col['section']),
            'desc'        => $desc,
            'task'        => $get($row, $col['task']),
            'by'          => $get($row, $col['by']),
            'priority'    => $get($row, $col['priority']),
            'statusT'     => $get($row, $col['statusT']),
            'responsible' => $get($row, $col['responsible']),
            'statusR'     => $get($row, $col['statusR']),
            'resDate'     => $get($row, $col['resDate']),
            'comments'    => $get($row, $col['comments']),
        ];
    }

    return $items;
}

// ─── ROUTER ───────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];
$data   = loadData();

// GET: list projects
if ($action === 'list' && $method === 'GET') {
    $summary = [];
    foreach ($data['projects'] as $pid => $project) {
        $totalItems = 0;
        foreach ($project['sessions'] as $s) $totalItems += count($s['items']);
        $lastSession = !empty($project['sessions']) ? end($project['sessions']) : null;
        $summary[] = [
            'id'            => $pid,
            'name'          => $project['name'],
            'created_at'    => $project['created_at'],
            'updated_at'    => $project['updated_at'],
            'session_count' => count($project['sessions']),
            'total_items'   => $totalItems,
            'last_upload'   => $lastSession ? $lastSession['uploaded_at'] : null,
            'last_filename' => $lastSession ? $lastSession['filename'] : null,
        ];
    }
    respond(['ok' => true, 'projects' => $summary]);
}

// GET: project detail
if ($action === 'project' && $method === 'GET') {
    $pid = $_GET['id'] ?? '';
    if (!isset($data['projects'][$pid])) respond(['ok' => false, 'error' => 'Proyecto no encontrado'], 404);
    respond(['ok' => true, 'project' => $data['projects'][$pid]]);
}

// POST: upload CSV
if ($action === 'upload' && $method === 'POST') {
    $projectName = trim($_POST['project_name'] ?? '');
    $projectId   = trim($_POST['project_id'] ?? '');

    if (empty($_FILES['csv']['tmp_name'])) {
        respond(['ok' => false, 'error' => 'No se recibió ningún archivo CSV'], 400);
    }

    $csvContent = file_get_contents($_FILES['csv']['tmp_name']);
    if ($csvContent === false) respond(['ok' => false, 'error' => 'No se pudo leer el archivo'], 400);

    // Encoding detection
    $encoding = mb_detect_encoding($csvContent, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $csvContent = mb_convert_encoding($csvContent, 'UTF-8', $encoding);
    }

    $items = parseCSV($csvContent);
    if (empty($items)) {
        respond(['ok' => false, 'error' => 'No se encontraron ítems válidos. Verificá que el CSV tenga columnas Bug/Fix y Descripción.'], 400);
    }

    // Resolve project
    if (!empty($projectId) && isset($data['projects'][$projectId])) {
        $pid = $projectId;
    } else {
        if (empty($projectName)) {
            $projectName = pathinfo($_FILES['csv']['name'], PATHINFO_FILENAME);
            $projectName = trim(preg_replace('/[_-]+/', ' ', $projectName));
        }
        $pid = slugify($projectName) . '-' . substr(md5(uniqid()), 0, 6);
        $data['projects'][$pid] = [
            'id'         => $pid,
            'name'       => $projectName,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'sessions'   => [],
        ];
    }

    // Count new vs updated
    $existingIds = [];
    foreach ($data['projects'][$pid]['sessions'] as $s) {
        foreach ($s['items'] as $item) {
            if ($item['id'] !== null) $existingIds[$item['id']] = true;
        }
    }

    $newCount = $updatedCount = 0;
    foreach ($items as $item) {
        if ($item['id'] !== null && isset($existingIds[$item['id']])) $updatedCount++;
        else $newCount++;
    }

    $session = [
        'id'            => 'session-' . date('Ymd-His'),
        'filename'      => $_FILES['csv']['name'],
        'uploaded_at'   => date('c'),
        'item_count'    => count($items),
        'new_count'     => $newCount,
        'updated_count' => $updatedCount,
        'items'         => $items,
    ];

    $data['projects'][$pid]['sessions'][] = $session;
    $data['projects'][$pid]['updated_at'] = date('c');

    if (!saveData($data)) {
        respond(['ok' => false, 'error' => 'No se pudo guardar data.json — verificá permisos de escritura en el servidor'], 500);
    }

    respond([
        'ok'            => true,
        'project_id'    => $pid,
        'project_name'  => $data['projects'][$pid]['name'],
        'session_id'    => $session['id'],
        'item_count'    => count($items),
        'new_count'     => $newCount,
        'updated_count' => $updatedCount,
    ]);
}

// POST: delete session
if ($action === 'delete_session' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid  = $body['project_id'] ?? '';
    $sid  = $body['session_id'] ?? '';
    if (!isset($data['projects'][$pid])) respond(['ok' => false, 'error' => 'Proyecto no encontrado'], 404);
    $data['projects'][$pid]['sessions'] = array_values(
        array_filter($data['projects'][$pid]['sessions'], fn($s) => $s['id'] !== $sid)
    );
    $data['projects'][$pid]['updated_at'] = date('c');
    saveData($data);
    respond(['ok' => true]);
}

// POST: delete project
if ($action === 'delete_project' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid  = $body['project_id'] ?? '';
    if (!isset($data['projects'][$pid])) respond(['ok' => false, 'error' => 'Proyecto no encontrado'], 404);
    unset($data['projects'][$pid]);
    saveData($data);
    respond(['ok' => true]);
}

respond(['ok' => false, 'error' => 'Acción no reconocida'], 400);
