<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$assign = new assign($context, $cm, $course);
$maxgrade = floatval($assign->get_instance()->grade);
$assignname = $assign->get_instance()->name;

$PAGE->set_url(new moodle_url('/local/dreamu_ai/stats.php', ['id' => $cmid]));
$PAGE->set_title('Statistiques de correction IA - ' . $assignname);
$PAGE->set_heading($course->fullname);

// Load Chart.js for the interactive charts on this page.
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'), true);

echo $OUTPUT->header();
echo $OUTPUT->heading('Statistiques : ' . format_string($assignname));

// Get validated + graded grades.
$records = $DB->get_records_select('local_dreamu_ai_grades',
    'assignid = :assignid AND grade IS NOT NULL AND status IN (:s1, :s2)',
    ['assignid' => $cm->instance, 's1' => 'graded', 's2' => 'validated']
);

if (empty($records)) {
    echo $OUTPUT->notification('Aucune note trouvée. Lancez d\'abord la correction IA.', 'info');
    $backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
    echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Calculate stats.
$grades = array_map(fn($r) => floatval($r->grade), $records);
sort($grades);

$count = count($grades);
$sum = array_sum($grades);
$average = $sum / $count;
$min = min($grades);
$max = max($grades);
$median = ($count % 2 === 0)
    ? ($grades[$count / 2 - 1] + $grades[$count / 2]) / 2
    : $grades[intdiv($count, 2)];

// Standard deviation.
$variance = array_sum(array_map(fn($g) => pow($g - $average, 2), $grades)) / $count;
$stddev = sqrt($variance);

// Distribution buckets.
$buckets = [];
$step = $maxgrade / 5;
for ($i = 0; $i < 5; $i++) {
    $low = round($i * $step, 1);
    $high = round(($i + 1) * $step, 1);
    $label = "{$low} - {$high}";
    $buckets[$label] = 0;
}
foreach ($grades as $g) {
    $idx = min(4, intval($g / $step));
    $keys = array_keys($buckets);
    $buckets[$keys[$idx]]++;
}

// Display stats cards.
echo '<div class="row mb-4">';

$stats = [
    ['Étudiants', $count, 'secondary'],
    ['Moyenne', number_format($average, 2) . ' / ' . $maxgrade, 'primary'],
    ['Médiane', number_format($median, 2), 'info'],
    ['Min', number_format($min, 2), 'danger'],
    ['Max', number_format($max, 2), 'success'],
    ['Écart-type', number_format($stddev, 2), 'warning'],
];

foreach ($stats as [$label, $value, $color]) {
    echo '<div class="col-md-2">';
    echo '<div class="card text-white bg-' . $color . ' mb-3">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">' . $value . '</h5>';
    echo '<p class="card-text">' . $label . '</p>';
    echo '</div></div></div>';
}

echo '</div>';

// Distribution histogram + status doughnut (Chart.js).
$validatedtotal = 0;
$pendingtotal = 0;
foreach ($records as $r) {
    if ($r->status === 'validated') {
        $validatedtotal++;
    } else {
        $pendingtotal++;
    }
}

echo '<div class="row mb-4">';
echo '<div class="col-md-8"><div class="card h-100"><div class="card-body">';
echo '<h4>Distribution des notes</h4>';
echo '<canvas id="dreamu-dist-chart" height="120" aria-label="Histogramme de distribution des notes" role="img"></canvas>';
echo '</div></div></div>';
echo '<div class="col-md-4"><div class="card h-100"><div class="card-body">';
echo '<h4>Statut des corrections</h4>';
echo '<canvas id="dreamu-status-chart" height="220" aria-label="Répartition validées / en attente" role="img"></canvas>';
echo '</div></div></div>';
echo '</div>';

// Grade table per student.
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Notes par étudiant</h4>';

// Search + status filter toolbar (client-side).
echo '<div class="d-flex flex-wrap align-items-center mb-3" style="gap:.5rem">';
echo '<input type="text" id="dreamu-student-search" class="form-control" style="max-width:280px" '
   . 'placeholder="' . s('🔍 Rechercher un étudiant...') . '" aria-label="Rechercher un étudiant">';
echo '<div class="btn-group btn-group-sm" role="group" aria-label="Filtrer par statut">';
echo '<button type="button" id="dreamu-spill-all" class="btn btn-outline-secondary active">Tous</button>';
echo '<button type="button" id="dreamu-spill-validated" class="btn btn-outline-success">Validées</button>';
echo '<button type="button" id="dreamu-spill-pending" class="btn btn-outline-warning">En attente</button>';
echo '</div></div>';

$table = new html_table();
$table->head = ['Étudiant', 'Note', 'Statut', 'Feedback (extrait)'];
$table->attributes['class'] = 'generaltable';
$table->id = 'dreamu-student-table';

foreach ($records as $record) {
    $user = $DB->get_record('user', ['id' => $record->userid]);
    if (!$user) continue;

    $statusmap = [
        'validated' => '<span class="badge badge-success bg-success">Validée</span>',
        'graded' => '<span class="badge badge-warning bg-warning">En attente</span>',
    ];

    $feedback = strip_tags($record->feedback ?? '');
    $feedback = shorten_text($feedback, 120);

    // Color the grade.
    $ratio = $maxgrade > 0 ? $record->grade / $maxgrade : 0;
    $gradecolor = $ratio >= 0.6 ? 'text-success' : ($ratio >= 0.4 ? 'text-warning' : 'text-danger');

    $table->data[] = [
        fullname($user),
        '<strong class="' . $gradecolor . '">' . number_format($record->grade, 2) . ' / ' . $maxgrade . '</strong>',
        $statusmap[$record->status] ?? $record->status,
        $feedback,
    ];
}

echo html_writer::table($table);

// Client-side table enhancer: live search, status filter pills, sortable columns.
echo '<script>'
   . 'function dreamuEnhanceTable(o){var t=document.getElementById(o.tableId);if(!t)return;var tb=t.tBodies[0];if(!tb)return;'
   . 'function rows(){return Array.prototype.slice.call(tb.rows);}'
   . 'var f="all";var s=o.searchId?document.getElementById(o.searchId):null;'
   . 'function apply(){var q=(s&&s.value||"").toLowerCase();rows().forEach(function(r){var tx=r.textContent.toLowerCase();var mq=!q||tx.indexOf(q)>-1;var mf=(f==="all");if(!mf&&o.statusCol!=null&&r.cells[o.statusCol]){mf=r.cells[o.statusCol].textContent.toLowerCase().indexOf(f)>-1;}r.style.display=(mq&&mf)?"":"none";});}'
   . 'if(s)s.addEventListener("input",apply);'
   . '(o.pills||[]).forEach(function(p){var e=document.getElementById(p.id);if(!e)return;e.addEventListener("click",function(){f=p.value;(o.pills).forEach(function(pp){var x=document.getElementById(pp.id);if(x)x.classList.toggle("active",pp.id===p.id);});apply();});});'
   . 'var hr=t.tHead?t.tHead.rows[0]:t.rows[0];if(hr){Array.prototype.forEach.call(hr.cells,function(th,i){if(o.noSort&&o.noSort.indexOf(i)>-1)return;th.style.cursor="pointer";th.setAttribute("title","Trier");var d=1;th.addEventListener("click",function(){d=-d;var rs=rows();rs.sort(function(a,b){var x=a.cells[i]?a.cells[i].textContent.trim():"",y=b.cells[i]?b.cells[i].textContent.trim():"";var nx=parseFloat(x.replace(",","."));var ny=parseFloat(y.replace(",","."));if(!isNaN(nx)&&!isNaN(ny))return (nx-ny)*d;return x.localeCompare(y,"fr")*d;});rs.forEach(function(r){tb.appendChild(r);});Array.prototype.forEach.call(hr.cells,function(h){var b=h.querySelector(".dreamu-arrow");if(b)b.remove();});var ar=document.createElement("span");ar.className="dreamu-arrow";ar.textContent=d>0?" ▲":" ▼";th.appendChild(ar);});});}}'
   . 'dreamuEnhanceTable({tableId:"dreamu-student-table",searchId:"dreamu-student-search",statusCol:2,noSort:[3],pills:[{id:"dreamu-spill-all",value:"all"},{id:"dreamu-spill-validated",value:"validé"},{id:"dreamu-spill-pending",value:"en attente"}]});'
   . '</script>';

echo '</div></div>';

// --- Calibration section: compare AI grades with manual grades ---
$manualgrades = $DB->get_records_sql(
    "SELECT ag.userid, ag.grade AS manualgrade
       FROM {assign_grades} ag
      WHERE ag.assignment = :assignid
        AND ag.grade >= 0
        AND ag.attemptnumber = (
            SELECT MAX(ag2.attemptnumber)
              FROM {assign_grades} ag2
             WHERE ag2.assignment = ag.assignment AND ag2.userid = ag.userid
        )",
    ['assignid' => $cm->instance]
);

if (!empty($manualgrades)) {
    // Build lookup of AI grades by userid.
    $aigrades_by_user = [];
    foreach ($records as $record) {
        $aigrades_by_user[$record->userid] = floatval($record->grade);
    }

    // Find users with both manual and AI grades.
    $calibrationrows = [];
    $diffs = [];
    $ai_vals = [];
    $manual_vals = [];

    foreach ($manualgrades as $mg) {
        if (isset($aigrades_by_user[$mg->userid])) {
            $aigrade = $aigrades_by_user[$mg->userid];
            $manualgrade = floatval($mg->manualgrade);
            $diff = $aigrade - $manualgrade;

            $user = $DB->get_record('user', ['id' => $mg->userid]);
            if (!$user) continue;

            $calibrationrows[] = [
                'name' => fullname($user),
                'aigrade' => $aigrade,
                'manualgrade' => $manualgrade,
                'diff' => $diff,
            ];

            $diffs[] = $diff;
            $ai_vals[] = $aigrade;
            $manual_vals[] = $manualgrade;
        }
    }

    if (!empty($calibrationrows)) {
        echo '<div class="card mb-4"><div class="card-body">';
        echo '<h4>Calibration : IA vs Notes manuelles</h4>';

        $caltable = new html_table();
        $caltable->head = ['Étudiant', 'Note IA', 'Note manuelle', 'Différence'];
        $caltable->attributes['class'] = 'generaltable';

        foreach ($calibrationrows as $row) {
            $diffcolor = abs($row['diff']) <= 1 ? 'text-success' : (abs($row['diff']) <= 3 ? 'text-warning' : 'text-danger');
            $diffsign = $row['diff'] >= 0 ? '+' : '';

            $caltable->data[] = [
                $row['name'],
                number_format($row['aigrade'], 2) . ' / ' . $maxgrade,
                number_format($row['manualgrade'], 2) . ' / ' . $maxgrade,
                '<strong class="' . $diffcolor . '">' . $diffsign . number_format($row['diff'], 2) . '</strong>',
            ];
        }

        echo html_writer::table($caltable);

        // Scatter plot: AI grade (y) vs manual grade (x) with a y=x reference line.
        echo '<div class="mt-3"><canvas id="dreamu-scatter-chart" height="160" aria-label="Nuage de points note IA contre note manuelle" role="img"></canvas></div>';

        // Calculate average difference.
        $avgdiff = array_sum($diffs) / count($diffs);
        $absdiffs = array_map('abs', $diffs);
        $avgabsdiff = array_sum($absdiffs) / count($absdiffs);

        // Calculate Pearson correlation.
        $n = count($ai_vals);
        $correlation = 0;
        if ($n >= 2) {
            $mean_ai = array_sum($ai_vals) / $n;
            $mean_manual = array_sum($manual_vals) / $n;

            $num = 0;
            $den_ai = 0;
            $den_manual = 0;
            for ($i = 0; $i < $n; $i++) {
                $da = $ai_vals[$i] - $mean_ai;
                $dm = $manual_vals[$i] - $mean_manual;
                $num += $da * $dm;
                $den_ai += $da * $da;
                $den_manual += $dm * $dm;
            }

            $denom = sqrt($den_ai) * sqrt($den_manual);
            if ($denom > 0) {
                $correlation = $num / $denom;
            }
        }

        echo '<div class="row mt-3">';
        $calstats = [
            ['Paires comparées', $n, 'secondary'],
            ['Diff. moyenne', ($avgdiff >= 0 ? '+' : '') . number_format($avgdiff, 2), 'info'],
            ['Diff. abs. moyenne', number_format($avgabsdiff, 2), 'warning'],
            ['Corrélation (Pearson)', number_format($correlation, 3), $correlation >= 0.7 ? 'success' : ($correlation >= 0.4 ? 'warning' : 'danger')],
        ];

        foreach ($calstats as [$label, $value, $color]) {
            echo '<div class="col-md-3">';
            echo '<div class="card text-white bg-' . $color . ' mb-3">';
            echo '<div class="card-body text-center">';
            echo '<h5 class="card-title">' . $value . '</h5>';
            echo '<p class="card-text">' . $label . '</p>';
            echo '</div></div></div>';
        }
        echo '</div>';

        echo '</div></div>';
    }
}

// --- Chart.js initialisation (data injected from PHP) ---
$scatterpoints = [];
if (!empty($ai_vals) && !empty($manual_vals)) {
    $n_scatter = count($ai_vals);
    for ($i = 0; $i < $n_scatter; $i++) {
        $scatterpoints[] = ['x' => round($manual_vals[$i], 2), 'y' => round($ai_vals[$i], 2)];
    }
}
$chartpayload = [
    'dist'    => ['labels' => array_keys($buckets), 'values' => array_values($buckets)],
    'status'  => ['validated' => $validatedtotal, 'pending' => $pendingtotal],
    'scatter' => $scatterpoints,
    'max'     => $maxgrade,
];
echo '<script>(function(){var D=' . json_encode($chartpayload, JSON_HEX_TAG | JSON_HEX_AMP) . ';'
   . 'function init(){if(!window.Chart){return setTimeout(init,60);}'
   . 'var d=document.getElementById("dreamu-dist-chart");'
   . 'if(d){new Chart(d,{type:"bar",data:{labels:D.dist.labels,datasets:[{label:"Nombre d\'étudiants",data:D.dist.values,backgroundColor:"#0f6cbf",borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});}'
   . 'var s=document.getElementById("dreamu-status-chart");'
   . 'if(s){new Chart(s,{type:"doughnut",data:{labels:["Validées","En attente"],datasets:[{data:[D.status.validated,D.status.pending],backgroundColor:["#198754","#ffc107"]}]},options:{responsive:true,plugins:{legend:{position:"bottom"}}}});}'
   . 'var sc=document.getElementById("dreamu-scatter-chart");'
   . 'if(sc&&D.scatter.length){new Chart(sc,{type:"scatter",data:{datasets:[{label:"IA vs manuelle",data:D.scatter,backgroundColor:"#0f6cbf",pointRadius:5},{type:"line",label:"Référence y=x",data:[{x:0,y:0},{x:D.max,y:D.max}],borderColor:"#dc3545",borderDash:[6,4],pointRadius:0,fill:false}]},options:{responsive:true,plugins:{legend:{position:"bottom"}},scales:{x:{title:{display:true,text:"Note manuelle"},min:0,max:D.max},y:{title:{display:true,text:"Note IA"},min:0,max:D.max}}}});}'
   . '}init();})();</script>';

// Export CSV button.
$csvurl = new moodle_url('/local/dreamu_ai/export_csv.php', ['id' => $cmid]);
echo '<a href="' . $csvurl . '" class="btn btn-outline-primary mr-2">Exporter CSV</a>';

// Back button.
$backurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Retour à la validation</a>';

echo $OUTPUT->footer();
