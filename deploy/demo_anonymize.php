<?php
/**
 * Demo: Delete two students and show how local_completionhistory anonymizes their ledger records.
 * Run: sudo -u www-data php /var/www/moodle45/demo_anonymize.php
 *
 * We delete:
 *   derek.osei    (id=35) — PREREQ-STRUGGLING, 1 prereq completion
 *   carlos.garcia (id=15) — MBA-WITHDRAWN, a few MBA completions
 */
define('CLI_SCRIPT', true);
require('/var/www/moodle45/config.php');
require_once($CFG->dirroot . '/user/lib.php');

// ── Targets ─────────────────────────────────────────────────────────────────
$targets = ['derek.osei', 'carlos.garcia'];

// ── Helper: print achievement rows for a list of userids (or userid=0) ──────
function show_achievements(array $userids, string $label): void {
    global $DB;
    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $rows = $DB->get_records_select(
        'local_completionhistory_achievement',
        "userid {$insql}",
        $params,
        'userid, completiontime'
    );
    echo "\n  {$label} (" . count($rows) . " rows):\n";
    foreach ($rows as $r) {
        printf(
            "    uuid=%-36s  userid=%-4s  idnumber=%-12s  course=%-30s  grade=%s  passed=%s\n",
            $r->ledgeruuid,
            $r->userid === '0' ? '[anon]' : $r->userid,
            $r->useridnumber_snapshot ?? '[NULL]',
            $r->courseshortname_snapshot,
            number_format((float)$r->grade_decimal, 1) . '%',
            $r->grade_passed ? 'YES' : 'NO'
        );
    }
}

function show_purge_audit(array $userids, string $label): void {
    global $DB;
    // Check if the purge_audit table exists
    $manager = $DB->get_manager();
    if (!$manager->table_exists('local_completionhistory_purge_audit')) {
        echo "\n  [{$label}] purge_audit table does not exist — skipping.\n";
        return;
    }
    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $rows = $DB->get_records_select(
        'local_completionhistory_purge_audit',
        "userid {$insql}",
        $params,
        'timecreated DESC'
    );
    echo "\n  {$label} (" . count($rows) . " purge audit rows):\n";
    foreach ($rows as $r) {
        $data = json_decode($r->datajson ?? '{}', true);
        printf(
            "    id=%-4s  userid=%-4s  reason=%-15s  affected=%s  anonymized=%s\n",
            $r->id,
            $r->userid,
            $r->reason,
            $data['achievement_rows_affected'] ?? '?',
            isset($data['anonymized']) ? ($data['anonymized'] ? 'YES' : 'NO') : '?'
        );
    }
}

// ── Resolve user IDs ─────────────────────────────────────────────────────────
$userids = [];
foreach ($targets as $uname) {
    $u = $DB->get_record('user', ['username' => $uname]);
    if ($u) {
        $userids[] = (int)$u->id;
    } else {
        echo "  WARNING: user '{$uname}' not found — skipping.\n";
    }
}

if (empty($userids)) {
    die("No target users found.\n");
}

echo "=== DEMO: local_completionhistory Anonymization on User Deletion ===\n";
echo "\nTarget users:\n";
foreach ($targets as $uname) {
    $u = $DB->get_record('user', ['username' => $uname]);
    if ($u) {
        $ach_count = $DB->count_records('local_completionhistory_achievement', ['userid' => $u->id]);
        echo "  {$u->firstname} {$u->lastname} (username={$uname}, id={$u->id}, achievements={$ach_count})\n";
    }
}

// ── BEFORE ───────────────────────────────────────────────────────────────────
echo "\n======= BEFORE DELETION =======";
show_achievements($userids, "Achievement ledger — identified records");
show_purge_audit($userids, "Purge audit — before deletion");

// ── DELETE USERS ─────────────────────────────────────────────────────────────
echo "\n======= DELETING USERS =======\n";
foreach ($targets as $uname) {
    $u = $DB->get_record('user', ['username' => $uname]);
    if (!$u) continue;

    echo "  Deleting {$u->firstname} {$u->lastname} (id={$u->id})...\n";
    delete_user($u); // Fires \core\event\user_deleted → triggers callbacks::user_deleted()
    echo "    Done.\n";
}

// ── AFTER ────────────────────────────────────────────────────────────────────
echo "\n======= AFTER DELETION =======";

// Show anonymized rows (userid=0) that belong to our ex-users — we identify
// them by the ledgeuuids that existed before (cross-check via purge audit timing).
// Easiest approach: show ALL userid=0 rows added after our deletion.
show_achievements([0], "Achievement ledger — anonymized (userid=0) rows");
show_purge_audit($userids, "Purge audit — deletion event logged");

// Show that the Moodle user record itself is anonymized too
echo "\n  Moodle user table — post-deletion state:\n";
foreach ($userids as $uid) {
    $u = $DB->get_record('user', ['id' => $uid]);
    if ($u) {
        printf(
            "    id=%-4s  username=%-30s  email=%-30s  deleted=%s\n",
            $u->id, $u->username, $u->email, $u->deleted
        );
    }
}

echo "\n======= KEY TAKEAWAYS =======\n";
echo "  • Moodle sets user.deleted=1 and scrambles username/email in mdl_user\n";
echo "  • local_completionhistory keeps ALL achievement rows (grades, dates, course names)\n";
echo "  • userid is set to 0 — no longer linkable to a person\n";
echo "  • useridnumber_snapshot is NULLed — student ID removed\n";
echo "  • artifacturl is NULLed — personal artifact link removed\n";
echo "  • Course name, grade, completion date, ledger UUID — all preserved\n";
echo "  • A purge audit row records the event for institutional records\n";
echo "\nDone!\n";
