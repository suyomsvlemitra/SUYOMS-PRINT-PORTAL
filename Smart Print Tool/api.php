<?php
/**
 * Smart Suyoms Print Tool - Backend API
 * Supports PHP 7.4+ without external dependencies.
 * Stores queue in JSON file database and saves uploaded files into uploads/ directory.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uploadDir = __DIR__ . '/uploads';
$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/queue.json';

// Ensure directories exist
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

// Initialize database if not present
if (!file_exists($dbFile)) {
    $initialData = [
        'shop' => [
            'name' => 'सुयोग ऑनलाईन झेरॉक्स सेंटर',
            'slug' => 'suyomsonline',
            'auto_print_enabled' => 0,
            'requires_approval' => 0,
            'auto_delete_minutes' => 5,
            'queue_paused' => 0
        ],
        'customers' => []
    ];
    file_put_contents($dbFile, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getDatabase($file) {
    $content = @file_get_contents($file);
    $data = json_decode($content, true);
    if (!$data || !is_array($data)) {
        return ['shop' => [], 'customers' => []];
    }
    return $data;
}

function saveDatabase($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$action = $_GET['action'] ?? '';

// Check & Run Auto-delete
function runAutoDelete(&$db, $uploadDir) {
    $autoDeleteMin = (int)($db['shop']['auto_delete_minutes'] ?? 5);
    if ($autoDeleteMin <= 0) return;

    $cutoff = time() - ($autoDeleteMin * 60);
    $newCustomers = [];

    foreach ($db['customers'] as $cust) {
        $custTime = (int)($cust['createdAtSec'] ?? 0);
        if ($custTime > 0 && $custTime < $cutoff) {
            // Delete customer files
            foreach ($cust['jobs'] as $job) {
                if (!empty($job['stored_filename'])) {
                    @unlink($uploadDir . '/' . $job['stored_filename']);
                }
            }
        } else {
            $newCustomers[] = $cust;
        }
    }
    $db['customers'] = $newCustomers;
}

$db = getDatabase($dbFile);
runAutoDelete($db, $uploadDir);
saveDatabase($dbFile, $db);

switch ($action) {
    // 1. GET QUEUE
    case 'get_queue':
        $shopFilter = $_GET['shop'] ?? '';
        $customers = $db['customers'] ?? [];

        // Calculate stats
        $totalCustomers = count($customers);
        $pendingFiles = 0;
        $printedFiles = 0;
        $failedFiles = 0;

        foreach ($customers as $c) {
            foreach ($c['jobs'] as $j) {
                if ($j['status'] === 'pending' || $j['status'] === 'printing') $pendingFiles++;
                else if ($j['status'] === 'printed') $printedFiles++;
                else if ($j['status'] === 'failed') $failedFiles++;
            }
        }

        echo json_encode([
            'success' => true,
            'shop' => $db['shop'],
            'stats' => [
                'total_customers' => $totalCustomers,
                'pending_files' => $pendingFiles,
                'printed_files' => $printedFiles,
                'failed_files' => $failedFiles
            ],
            'customers' => array_values($customers)
        ]);
        break;

    // 2. UPLOAD FILES TO QUEUE
    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Invalid method']);
            exit;
        }

        $customerName = trim($_POST['customer_name'] ?? 'Walk-in Customer');
        $phone = trim($_POST['phone'] ?? '');
        $copies = max(1, (int)($_POST['copies'] ?? 1));
        $colorMode = in_array($_POST['color_mode'] ?? '', ['color', 'bw']) ? $_POST['color_mode'] : 'bw';
        $pages = trim($_POST['pages'] ?? '');
        $tokenNum = (int)($_POST['token_number'] ?? rand(100, 999));

        if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
            echo json_encode(['success' => false, 'error' => 'No files uploaded']);
            exit;
        }

        $customerId = 'cust_' . time() . '_' . rand(10, 99);
        $jobs = [];
        $filesCount = count($_FILES['files']['name']);

        for ($i = 0; $i < $filesCount; $i++) {
            if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                $origName = basename($_FILES['files']['name'][$i]);
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $jobId = 'job_' . time() . '_' . $i . '_' . rand(10, 99);
                $storedName = $jobId . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
                $targetPath = $uploadDir . '/' . $storedName;

                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetPath)) {
                    $jobs[] = [
                        'id' => $jobId,
                        'serial_number' => $i + 1,
                        'original_filename' => $origName,
                        'stored_filename' => $storedName,
                        'file_path' => 'uploads/' . $storedName,
                        'file_size' => (int)$_FILES['files']['size'][$i],
                        'file_type' => $ext,
                        'copies' => $copies,
                        'color_mode' => $colorMode,
                        'pages' => $pages,
                        'status' => 'pending',
                        'createdAt' => time() * 1000
                    ];
                }
            }
        }

        if (empty($jobs)) {
            echo json_encode(['success' => false, 'error' => 'Failed to process files']);
            exit;
        }

        $newCustomer = [
            'id' => $customerId,
            'customer_name' => $customerName,
            'token_number' => $tokenNum,
            'phone' => $phone,
            'createdAt' => time() * 1000,
            'createdAtSec' => time(),
            'jobs' => $jobs
        ];

        // Insert at beginning of queue
        array_unshift($db['customers'], $newCustomer);
        saveDatabase($dbFile, $db);

        echo json_encode([
            'success' => true,
            'customer_id' => $customerId,
            'token_number' => $tokenNum,
            'jobs_count' => count($jobs)
        ]);
        break;

    // 3. UPDATE JOB STATUS
    case 'update_status':
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? $_POST;
        $custId = $body['customer_id'] ?? '';
        $jobId = $body['job_id'] ?? '';
        $status = $body['status'] ?? 'printed';

        $found = false;
        foreach ($db['customers'] as &$c) {
            if ($c['id'] === $custId || empty($custId)) {
                foreach ($c['jobs'] as &$j) {
                    if ($j['id'] === $jobId) {
                        $j['status'] = $status;
                        $found = true;
                        break;
                    }
                }
            }
        }
        if ($found) {
            saveDatabase($dbFile, $db);
        }
        echo json_encode(['success' => $found]);
        break;

    // 4. UPDATE SETTINGS
    case 'update_settings':
        if (isset($_POST['shop_name'])) $db['shop']['name'] = trim($_POST['shop_name']);
        if (isset($_POST['auto_print'])) $db['shop']['auto_print_enabled'] = (int)$_POST['auto_print'];
        if (isset($_POST['requires_approval'])) $db['shop']['requires_approval'] = (int)$_POST['requires_approval'];
        if (isset($_POST['auto_delete_minutes'])) $db['shop']['auto_delete_minutes'] = (int)$_POST['auto_delete_minutes'];
        if (isset($_POST['queue_paused'])) $db['shop']['queue_paused'] = (int)$_POST['queue_paused'];

        saveDatabase($dbFile, $db);
        echo json_encode(['success' => true, 'shop' => $db['shop']]);
        break;

    // 5. CLEAR COMPLETED JOBS
    case 'clear_completed':
        $newCustomers = [];
        foreach ($db['customers'] as $c) {
            $pendingJobs = [];
            foreach ($c['jobs'] as $j) {
                if ($j['status'] === 'printed') {
                    if (!empty($j['stored_filename'])) {
                        @unlink($uploadDir . '/' . $j['stored_filename']);
                    }
                } else {
                    $pendingJobs[] = $j;
                }
            }
            if (count($pendingJobs) > 0) {
                $c['jobs'] = $pendingJobs;
                $newCustomers[] = $c;
            }
        }
        $db['customers'] = $newCustomers;
        saveDatabase($dbFile, $db);
        echo json_encode(['success' => true]);
        break;

    // 6. DELETE JOB
    case 'delete_job':
        $jobId = $_GET['job_id'] ?? ($_POST['job_id'] ?? '');
        $custId = $_GET['customer_id'] ?? ($_POST['customer_id'] ?? '');

        $newCustomers = [];
        $db['customers'] = array_values($db['customers']);
        foreach ($db['customers'] as $c) {
            $newJobs = [];
            foreach ($c['jobs'] as $j) {
                if ($j['id'] === $jobId) {
                    if (!empty($j['stored_filename'])) {
                        @unlink($uploadDir . '/' . $j['stored_filename']);
                    }
                } else {
                    $newJobs[] = $j;
                }
            }
            if (count($newJobs) > 0) {
                $c['jobs'] = $newJobs;
                $newCustomers[] = $c;
            }
        }
        $db['customers'] = $newCustomers;
        saveDatabase($dbFile, $db);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode([
            'success' => true,
            'message' => 'Smart Suyoms Print API is running',
            'version' => '1.0'
        ]);
        break;
}