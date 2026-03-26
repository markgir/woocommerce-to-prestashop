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

// ── helpers ────────────────────────────────────────────────────────────────

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $status = 400): never
{
    respond(['success' => false, 'error' => $message], $status);
}

function getJson(): array
{
    $body = file_get_contents('php://input');
    return json_decode($body ?: '{}', true) ?? [];
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
    $data = getJson();
    try {
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
        respondError('WooCommerce connection failed: ' . $e->getMessage());
    }
}

function actionTestPs(): never
{
    $data = getJson();
    try {
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
        respondError('PrestaShop connection failed: ' . $e->getMessage());
    }
}

function actionAnalyze(): never
{
    $data   = getJson();
    $wcCfg  = $data['wc'] ?? [];
    $psCfg  = $data['ps'] ?? [];

    try {
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
    $data      = getJson();
    $sessionId = $data['session_id'] ?? 'default';
    $wcCfg     = $data['wc'] ?? [];
    $psCfg     = $data['ps'] ?? [];
    $options   = $data['options'] ?? [];

    try {
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
    $data      = getJson();
    $sessionId = $data['session_id'] ?? 'default';
    $wcCfg     = $data['wc'] ?? [];
    $psCfg     = $data['ps'] ?? [];

    try {
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
