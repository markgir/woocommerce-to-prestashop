<?php

declare(strict_types=1);

/**
 * api.php – AJAX endpoint for the WooCommerce → PrestaShop migration tool.
 *
 * All requests are POST with Content-Type: application/json.
 * All responses are JSON.
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/DebugLogger.php';
require_once __DIR__ . '/src/FieldMapper.php';
require_once __DIR__ . '/src/WooCommerce/WCImporter.php';
require_once __DIR__ . '/src/PrestaShop/PSExporter.php';
require_once __DIR__ . '/src/Migrator.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Convert PHP warnings/notices to exceptions so they can be caught by try/catch
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Respect the @ operator: error_reporting() returns 0 when @ suppression is active
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * Recursively sanitise a value for safe JSON encoding:
 * strips null bytes and converts invalid UTF-8 sequences.
 */
function sanitiseForJson(mixed $value): mixed
{
    if (is_string($value)) {
        // Remove null bytes that cause json_encode to fail
        $value = str_replace("\0", '', $value);
        // Replace invalid UTF-8 sequences with the UTF-8 replacement character
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return $value;
    }
    if (is_array($value)) {
        return array_map(fn($v) => sanitiseForJson($v), $value);
    }
    return $value;
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json  = json_encode($data, $flags);

    if ($json === false) {
        // Sanitise strings and retry with invalid-UTF-8 substitution
        $data = sanitiseForJson($data);
        $json = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    if ($json === false) {
        // Last-resort fallback: plain error envelope
        $json = '{"success":false,"error":"Response encoding error"}';
    }

    echo $json;

    // Flush output buffers so the response reaches the client before exit
    if (ob_get_level() > 0) {
        ob_end_flush();
    }

    exit;
}

function respondError(string $message, int $status = 400): never
{
    error_log("api.php error [{$status}]: {$message}");
    respond(['success' => false, 'error' => $message], $status);
}

function getJson(): array
{
    $body = file_get_contents('php://input');
    if ($body === false) {
        return [];
    }
    $decoded = json_decode($body ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function buildDb(array $cfg): Database
{
    return new Database(
        host    : $cfg['host']     ?? '127.0.0.1',
        port    : (string) ($cfg['port']     ?? '3306'),
        dbname  : $cfg['dbname']   ?? '',
        user    : $cfg['user']     ?? '',
        password: $cfg['password'] ?? '',
        prefix  : $cfg['prefix']   ?? ''
    );
}

// ── routing ────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
if (!$action) {
    respondError('Missing action parameter.');
}

try {
    match ($action) {
        'test_wc'       => actionTestWc(),
        'test_ps'       => actionTestPs(),
        'analyze'       => actionAnalyze(),
        'start'         => actionMigrate(),
        'step'          => actionMigrate(),
        'get_progress'  => actionGetProgress(),
        'get_logs'      => actionGetLogs(),
        'reset'         => actionReset(),
        default         => respondError("Unknown action: {$action}"),
    };
} catch (\Throwable $e) {
    respondError('Server error: ' . $e->getMessage(), 500);
}

// ── actions ────────────────────────────────────────────────────────────────

function actionTestWc(): never
{
    try {
        $data = getJson();
        $db  = buildDb($data);
        $wc  = new WCImporter($db, new DebugLogger('test'));
        respond([
            'success'  => true,
            'version'  => $wc->getWooCommerceVersion(),
            'site_url' => $wc->getSiteUrl(),
            'counts'   => [
                'categories' => $wc->countCategories(),
                'products'   => $wc->countProducts(),
                'variations' => $wc->countVariations(),
                'attributes' => $wc->countAttributes(),
            ],
        ]);
    } catch (\Throwable $e) {
        respond(['success' => false, 'error' => 'WooCommerce connection failed: ' . $e->getMessage()]);
    }
}

function actionTestPs(): never
{
    try {
        $data = getJson();
        $db  = buildDb($data);
        $log = new DebugLogger('test');
        $ps  = new PSExporter($db, $log);
        respond([
            'success'  => true,
            'version'  => $ps->getPrestaShopVersion(),
            'id_lang'  => $ps->getDefaultLanguageId(),
            'id_shop'  => $ps->getDefaultShopId(),
        ]);
    } catch (\Throwable $e) {
        respond(['success' => false, 'error' => 'PrestaShop connection failed: ' . $e->getMessage()]);
    }
}

function actionAnalyze(): never
{
    try {
        $data   = getJson();
        $wcCfg  = $data['wc'] ?? [];
        $psCfg  = $data['ps'] ?? [];
        $wcDb = buildDb($wcCfg);
        $wc   = new WCImporter($wcDb, new DebugLogger('analyze'));
        $info = [
            'wc_version'    => $wc->getWooCommerceVersion(),
            'site_url'      => $wc->getSiteUrl(),
            'categories'    => $wc->countCategories(),
            'products'      => $wc->countProducts(),
            'variations'    => $wc->countVariations(),
            'attributes'    => $wc->countAttributes(),
        ];

        $psDb = buildDb($psCfg);
        $log  = new DebugLogger('analyze');
        $ps   = new PSExporter($psDb, $log);
        $info['ps_version'] = $ps->getPrestaShopVersion();
        $info['id_lang']    = $ps->getDefaultLanguageId();
        $info['id_shop']    = $ps->getDefaultShopId();

        respond(['success' => true, 'info' => $info]);
    } catch (\Throwable $e) {
        respondError('Analysis failed: ' . $e->getMessage());
    }
}

function actionMigrate(): never
{
    try {
        $data      = getJson();
        $sessionId = $data['session_id'] ?? 'default';
        $wcCfg     = $data['wc'] ?? [];
        $psCfg     = $data['ps'] ?? [];
        $options   = $data['options'] ?? [];
        $wcDb  = buildDb($wcCfg);
        $log   = new DebugLogger($sessionId);
        $wc    = new WCImporter($wcDb, $log);

        $psDb  = buildDb($psCfg);
        $idLang= (int) ($psCfg['id_lang'] ?? 1);
        $idShop= (int) ($psCfg['id_shop'] ?? 1);
        $psVer = $psCfg['ps_version'] ?? '1.7';
        $ps    = new PSExporter($psDb, $log, $psVer, $idLang, $idShop);

        $batchSize = (int) ($options['batch_size'] ?? 20);
        $migrator  = new Migrator($wc, $ps, $log, $sessionId, $batchSize);

        $state = $migrator->runStep($options);
        respond(['success' => true, 'state' => $state]);
    } catch (\Throwable $e) {
        respondError('Migration error: ' . $e->getMessage());
    }
}

function actionGetProgress(): never
{
    $sessionId    = $_GET['session_id'] ?? 'default';
    $progressFile = __DIR__ . '/migration_progress/progress_'
        . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';

    if (!file_exists($progressFile)) {
        respond(['success' => true, 'state' => ['status' => 'idle']]);
    }

    $state = json_decode(file_get_contents($progressFile), true) ?? [];
    respond(['success' => true, 'state' => $state]);
}

function actionGetLogs(): never
{
    $sessionId = $_GET['session_id'] ?? 'default';
    $offset    = (int) ($_GET['offset'] ?? 0);

    $log    = new DebugLogger($sessionId);
    $result = $log->getEntries($offset);
    respond(['success' => true] + $result);
}

function actionReset(): never
{
    try {
        $data      = getJson();
        $sessionId = $data['session_id'] ?? 'default';
        $wcCfg     = $data['wc'] ?? [];
        $psCfg     = $data['ps'] ?? [];
        $wcDb = buildDb($wcCfg);
        $log  = new DebugLogger($sessionId);
        $wc   = new WCImporter($wcDb, $log);

        $psDb = buildDb($psCfg);
        $ps   = new PSExporter($psDb, $log);

        $migrator = new Migrator($wc, $ps, $log, $sessionId);
        $migrator->reset();
        respond(['success' => true, 'message' => 'Migration state reset.']);
    } catch (\Throwable $e) {
        respondError('Reset failed: ' . $e->getMessage());
    }
}
