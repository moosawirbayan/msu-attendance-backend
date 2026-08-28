<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';

$database = new Database();
$db = $database->getConnection();

// ✅ No timezone conversion — check_in_time is already stored as PH time
// ✅ PHP: get today's date in PH time by adding +8 to UTC
$phNow  = new DateTime('now', new DateTimeZone('UTC'));
$phNow->modify('+8 hours');
$today  = $phNow->format('Y-m-d');
$displayDate = $phNow->format('l, F j, Y');

// ── FIX: needed to determine which classes have already ENDED today,
// so "Absent Today" only counts students whose class session is over —
// not students whose class simply hasn't happened yet.
$todayDayName = $phNow->format('l');   // e.g. "Monday"
$nowTimeStr   = $phNow->format('H:i:s');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
$token = str_replace('Bearer ', '', $authHeader);

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit();
}

$decoded = explode(':', base64_decode($token));
$userId  = $decoded[0] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

try {
    // Get instructor info
    $userStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Get total enrolled students across all classes
    $enrolledStmt = $db->prepare("
        SELECT COUNT(e.student_id) as total 
        FROM enrollments e 
        JOIN classes c ON e.class_id = c.id 
        WHERE c.instructor_id = ?
    ");
    $enrolledStmt->execute([$userId]);
    $enrolled = $enrolledStmt->fetch(PDO::FETCH_ASSOC);

    // Get total classes
    $classesStmt = $db->prepare("SELECT COUNT(*) as total FROM classes WHERE instructor_id = ?");
    $classesStmt->execute([$userId]);
    $classCount = $classesStmt->fetch(PDO::FETCH_ASSOC);

    // Present today — check_in_time is PH time, compare directly
    $presentStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM attendance a 
        JOIN classes c ON a.class_id = c.id 
        JOIN enrollments e 
            ON e.student_id = a.student_id 
            AND e.class_id = a.class_id
        WHERE c.instructor_id = ? 
        AND DATE(a.check_in_time) = ?
        AND a.status = 'present'
    ");
    $presentStmt->execute([$userId, $today]);
    $present = $presentStmt->fetch(PDO::FETCH_ASSOC);

    $totalEnrolled  = (int)($enrolled['total'] ?? 0);
    $presentCount   = (int)($present['total']  ?? 0);

    // ─────────────────────────────────────────────
    // ✅ FIX: "Absent Today" should start at 0 and only count a student
    // once their class session for today has actually ENDED. Previously
    // this was `max(0, $totalEnrolled - $presentCount)`, which counted
    // a student as absent the instant the dashboard was checked — even
    // hours before their class was scheduled to start.
    //
    // Step 1: find which of this instructor's classes are scheduled for
    // today AND whose end_time has already passed.
    // ─────────────────────────────────────────────
    $classSchedStmt = $db->prepare("
        SELECT id, days, end_time
        FROM classes
        WHERE instructor_id = ?
    ");
    $classSchedStmt->execute([$userId]);
    $allClasses = $classSchedStmt->fetchAll(PDO::FETCH_ASSOC);

    $endedClassIds = [];
    $debugClassInfo = []; // ── TEMP DEBUG — remove once issue is found ──
    foreach ($allClasses as $cls) {
        if (empty($cls['days']) || empty($cls['end_time'])) continue;

        $scheduledDays = array_map('trim', explode(',', $cls['days']));
        $isScheduledToday = in_array($todayDayName, $scheduledDays);

        // end_time from DB may be "HH:MM:SS" or "HH:MM" — normalize both
        // sides to "HH:MM:SS" so the string comparison is reliable.
        $endTime = strlen($cls['end_time']) === 5 ? $cls['end_time'] . ':00' : $cls['end_time'];
        $hasEnded = $isScheduledToday && ($nowTimeStr >= $endTime);

        // ── TEMP DEBUG ──
        $debugClassInfo[] = [
            'class_id'          => $cls['id'],
            'raw_days'          => $cls['days'],
            'parsed_days'       => $scheduledDays,
            'today_day_name'    => $todayDayName,
            'is_scheduled_today'=> $isScheduledToday,
            'raw_end_time'      => $cls['end_time'],
            'normalized_end'    => $endTime,
            'now_time_str'      => $nowTimeStr,
            'marked_as_ended'   => $hasEnded,
        ];

        if ($hasEnded) {
            $endedClassIds[] = (int)$cls['id'];
        }
    }

    // Step 2: among only those ended-today classes, count enrollments
    // that have no 'present' or 'late' attendance record for today.
    if (empty($endedClassIds)) {
        $absentCount = 0;
    } else {
        $placeholders1 = implode(',', array_fill(0, count($endedClassIds), '?'));
        $placeholders2 = implode(',', array_fill(0, count($endedClassIds), '?'));

        $absentStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM enrollments e
            WHERE e.class_id IN ($placeholders1)
            AND NOT EXISTS (
                SELECT 1 FROM attendance a
                WHERE a.class_id = e.class_id
                AND a.student_id = e.student_id
                AND DATE(a.check_in_time) = ?
                AND a.status IN ('present', 'late')
            )
        ");
        $params = array_merge($endedClassIds, [$today]);
        $absentStmt->execute($params);
        $absentRow = $absentStmt->fetch(PDO::FETCH_ASSOC);
        $absentCount = (int)($absentRow['total'] ?? 0);
    }

    // ─────────────────────────────────────────────
    // ✅ FIX: Attendance Rate now uses the same "ended classes only" scope
    // as absentToday, instead of dividing today's total present count by
    // ALL enrollments (including classes that haven't happened yet).
    // Previously a rate could look artificially low early in the day —
    // e.g. only 2 of 10 total-enrolled students had checked in by 8am,
    // even though the other 8 students simply hadn't had class yet.
    //
    // Denominator: enrollments in classes that have ended today.
    // Numerator: of those, enrollments with a 'present' or 'late' record
    // for today (both count as "attended" — matches the absentToday
    // exclusion logic above).
    // ─────────────────────────────────────────────
    if (empty($endedClassIds)) {
        $attendanceRate = 0;
    } else {
        $placeholdersRate = implode(',', array_fill(0, count($endedClassIds), '?'));

        $endedEnrolledStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM enrollments e
            WHERE e.class_id IN ($placeholdersRate)
        ");
        $endedEnrolledStmt->execute($endedClassIds);
        $endedEnrolledCount = (int)($endedEnrolledStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $placeholdersRate2 = implode(',', array_fill(0, count($endedClassIds), '?'));
        $endedAttendedStmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM enrollments e
            WHERE e.class_id IN ($placeholdersRate2)
            AND EXISTS (
                SELECT 1 FROM attendance a
                WHERE a.class_id = e.class_id
                AND a.student_id = e.student_id
                AND DATE(a.check_in_time) = ?
                AND a.status IN ('present', 'late')
            )
        ");
        $endedAttendedParams = array_merge($endedClassIds, [$today]);
        $endedAttendedStmt->execute($endedAttendedParams);
        $endedAttendedCount = (int)($endedAttendedStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $attendanceRate = $endedEnrolledCount > 0
            ? min(100, round(($endedAttendedCount / $endedEnrolledCount) * 100))
            : 0;
    }

    // Recent attendance — return check_in_time as-is (already PH time)
    $recentStmt = $db->prepare("
        SELECT
            CONCAT(
                s.first_name,
                CASE WHEN s.middle_initial IS NOT NULL 
                     THEN CONCAT(' ', s.middle_initial, '. ') 
                     ELSE ' ' END,
                s.last_name
            ) AS student_name,
            c.class_name,
            c.class_code,
            DATE_FORMAT(a.check_in_time, '%Y-%m-%d %H:%i:%s') AS checkin_time,
            a.status
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN classes  c ON a.class_id   = c.id
        JOIN enrollments e
            ON e.student_id = a.student_id
            AND e.class_id = a.class_id
        WHERE c.instructor_id = ?
        ORDER BY a.check_in_time DESC
        LIMIT 5
    ");
    $recentStmt->execute([$userId]);
    $recentAttendance = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Class breakdown — per subject attendance today
    $breakdownStmt = $db->prepare("
        SELECT
            c.class_code,
            c.class_name,
            COUNT(DISTINCT e.student_id) AS total,
            COUNT(DISTINCT CASE 
                WHEN a.status IN ('present', 'late') 
                AND DATE(a.check_in_time) = ?
                THEN a.student_id 
            END) AS present
        FROM classes c
        JOIN enrollments e ON e.class_id = c.id
        LEFT JOIN attendance a 
            ON a.student_id = e.student_id 
            AND a.class_id = c.id
        WHERE c.instructor_id = ?
        GROUP BY c.id, c.class_code, c.class_name
        ORDER BY c.class_code ASC
    ");
    $breakdownStmt->execute([$today, $userId]);
    $classBreakdownRaw = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

    $classBreakdown = array_map(function($row) {
        return [
            'class_code' => $row['class_code'],
            'class_name' => $row['class_name'],
            'present'    => (int)$row['present'],
            'total'      => (int)$row['total'],
        ];
    }, $classBreakdownRaw);

    // Active Classes
    $activeStmt = $db->prepare("
        SELECT
            c.id,
            c.class_code,
            c.class_name,
            c.room,
            COUNT(e.id) AS total_students
        FROM classes c
        LEFT JOIN enrollments e 
            ON e.class_id = c.id 
            AND e.status = 'active'
        WHERE c.instructor_id = ?
          AND c.is_active = 1
        GROUP BY c.id, c.class_code, c.class_name, c.room
        ORDER BY c.class_name ASC
    ");
    $activeStmt->execute([$userId]);
    $activeClassesRaw = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    $activeClasses = array_map(function($row) {
        return [
            'id'             => (int)$row['id'],
            'class_code'     => $row['class_code'],
            'class_name'     => $row['class_name'],
            'room'           => $row['room'] ?? null,
            'total_students' => (int)$row['total_students'],
        ];
    }, $activeClassesRaw);

    $responseData = [
        'instructorName'   => $user['name'] ?? 'Instructor',
        'date'             => $displayDate,
        'enrolledStudents' => $totalEnrolled,
        'enrolledClasses'  => (int)($classCount['total'] ?? 0),
        'presentToday'     => $presentCount,
        'absentToday'      => $absentCount,
        'attendanceRate'   => $attendanceRate,
        'recentAttendance' => $recentAttendance,
        'classBreakdown'   => $classBreakdown,
        'activeClasses'    => $activeClasses,
    ];

    // ── TEMP DEBUG — hit this endpoint with ?debug=1 to see raw schedule
    // comparison values. Remove this whole block once the issue is found. ──
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        $responseData['_debug'] = [
            'ph_now'          => $phNow->format('Y-m-d H:i:s'),
            'today_day_name'  => $todayDayName,
            'now_time_str'    => $nowTimeStr,
            'classes'         => $debugClassInfo,
            'ended_class_ids' => $endedClassIds,
        ];
    }

    echo json_encode([
        'success' => true,
        'data'    => $responseData
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>