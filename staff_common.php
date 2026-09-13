<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

function ensureStaffSchema(PDO $pdo): void {
    $isMysql = defined('DB_DRIVER') && DB_DRIVER === 'mysql';
    $isPgsql = defined('DB_DRIVER') && DB_DRIVER === 'pgsql';
    if ($isPgsql) {
        $pk = 'id SERIAL PRIMARY KEY';
        $vc50 = 'VARCHAR(50)';
        $vc20 = 'VARCHAR(20)';
        $auto = 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $engine = '';
    } else {
        $pk = $isMysql ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
        $vc50 = $isMysql ? 'VARCHAR(50)' : 'TEXT';
        $vc20 = $isMysql ? 'VARCHAR(20)' : 'TEXT';
        $auto = $isMysql ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $engine = $isMysql ? ' ENGINE=InnoDB' : '';
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff ($pk, employee_code $vc50 UNIQUE, name TEXT NOT NULL, phone TEXT, position TEXT, join_date TEXT, basic_salary REAL NOT NULL DEFAULT 0, allowance REAL NOT NULL DEFAULT 0, deduction REAL NOT NULL DEFAULT 0, weekly_off_day INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at $auto)$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_attendance ($pk, staff_id INTEGER NOT NULL, attendance_date TEXT NOT NULL, status $vc20 NOT NULL, notes TEXT, created_at $auto, UNIQUE(staff_id, attendance_date), FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE CASCADE)$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_holidays ($pk, holiday_date TEXT NOT NULL UNIQUE, holiday_name TEXT NOT NULL, paid INTEGER NOT NULL DEFAULT 1, created_at $auto)$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_leave ($pk, staff_id INTEGER NOT NULL, leave_date TEXT NOT NULL, leave_type TEXT NOT NULL DEFAULT 'Leave', paid INTEGER NOT NULL DEFAULT 1, notes TEXT, created_at $auto, UNIQUE(staff_id, leave_date), FOREIGN KEY(staff_id) REFERENCES staff(id) ON DELETE CASCADE)$engine");
}
ensureStaffSchema($pdo);

function monthBounds(string $month): array {
    if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) $month = date('Y-m');
    $start = new DateTimeImmutable($month . '-01');
    return [$start, $start->modify('first day of next month')];
}
function staffMonthReport(PDO $pdo, int $staffId, string $month): array {
    [$start, $next] = monthBounds($month); $end = $next->modify('-1 day');
    $s = $pdo->prepare('SELECT * FROM staff WHERE id=?'); $s->execute([$staffId]); $staff=$s->fetch(PDO::FETCH_ASSOC);
    if (!$staff) throw new RuntimeException('Staff member not found');
    $att=$pdo->prepare('SELECT attendance_date,status,notes FROM staff_attendance WHERE staff_id=? AND attendance_date>=? AND attendance_date<?'); $att->execute([$staffId,$start->format('Y-m-d'),$next->format('Y-m-d')]);
    $attendance=[]; foreach($att as $r) $attendance[$r['attendance_date']]=$r;
    $lv=$pdo->prepare('SELECT leave_date,leave_type,paid,notes FROM staff_leave WHERE staff_id=? AND leave_date>=? AND leave_date<?'); $lv->execute([$staffId,$start->format('Y-m-d'),$next->format('Y-m-d')]);
    $leave=[]; foreach($lv as $r) $leave[$r['leave_date']]=$r;
    $hol=$pdo->prepare('SELECT holiday_date,holiday_name,paid FROM staff_holidays WHERE holiday_date>=? AND holiday_date<?'); $hol->execute([$start->format('Y-m-d'),$next->format('Y-m-d')]);
    $holidays=[]; foreach($hol as $r) $holidays[$r['holiday_date']]=$r;
    $rows=[]; $counts=['Present'=>0,'Absent'=>0,'Off Day'=>0,'Leave'=>0,'Holiday'=>0]; $working=0; $paidDays=0; $unpaidLeave=0;
    for($d=$start;$d<$next;$d=$d->modify('+1 day')){
        $date=$d->format('Y-m-d'); $dow=(int)$d->format('w'); $status='Absent'; $label=''; $paid=true;
        if(isset($holidays[$date])) { $status='Holiday'; $label=$holidays[$date]['holiday_name']; $paid=(int)$holidays[$date]['paid']===1; }
        elseif($dow === (int)$staff['weekly_off_day']) { $status='Off Day'; $label='Weekly off'; }
        elseif(isset($leave[$date])) { $status='Leave'; $label=$leave[$date]['leave_type']; $paid=(int)$leave[$date]['paid']===1; }
        elseif(isset($attendance[$date])) { $status=$attendance[$date]['status']; $label=$attendance[$date]['notes'] ?? ''; $paid=in_array($status,['Present','Holiday','Off Day'],true); }
        if($status !== 'Off Day' && $status !== 'Holiday') $working++;
        if(isset($counts[$status])) $counts[$status]++;
        if($status==='Present' || ($status==='Leave' && $paid)) $paidDays++;
        if($status==='Leave' && !$paid) $unpaidLeave++;
        $rows[]=['date'=>$date,'day'=>$d->format('D'),'status'=>$status,'label'=>$label,'paid'=>$paid];
    }
    $dailyRate=$working>0 ? ((float)$staff['basic_salary']/$working) : 0;
    $salary=max(0,$dailyRate*$paidDays + (float)$staff['allowance'] - (float)$staff['deduction']);
    return compact('staff','rows','counts','working','paidDays','unpaidLeave','dailyRate','salary','month','start','end');
}
function moneyLkr(float $n): string { return 'LKR ' . number_format($n,2); }
