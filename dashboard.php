<?php
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_ai:grade', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Dashboard IA - ' . $course->fullname);
$PAGE->set_heading($course->fullname);

// Load Chart.js for the dashboard charts.
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'), true);

echo $OUTPUT->header();
echo $OUTPUT->heading('Dashboard IA');

// Get all assignments in this course that have AI grading configured.
$assignments = $DB->get_records_sql(
    "SELECT a.id, a.name, a.grade AS maxgrade, cm.id AS cmid, c.enabled
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = :courseid1
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
       LEFT JOIN {local_dreamu_ai_config} c ON c.assignid = a.id
      WHERE a.course = :courseid2
        AND c.enabled = 1
   ORDER BY a.name",
    ['courseid1' => $courseid, 'courseid2' => $courseid]
);

if (empty($assignments)) {
    echo $OUTPUT->notification('Aucun devoir avec correction IA activée dans ce cours.', 'info');
    $backurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Collect stats for each assignment.
$allstats = [];
$globalsum = 0;
$globalcount = 0;
$globalmin = null;
$globalmax = null;
$globalvalidated = 0;
$globalpending = 0;

foreach ($assignments as $assign) {
    $records = $DB->get_records_select('local_dreamu_ai_grades',
        'assignid = :assignid AND grade IS NOT NULL AND status IN (:s1, :s2)',
        ['assignid' => $assign->id, 's1' => 'graded', 's2' => 'validated']
    );

    $stat = new stdClass();
    $stat->name = $assign->name;
    $stat->cmid = $assign->cmid;
    $stat->maxgrade = floatval($assign->maxgrade);
    $stat->count = count($records);
    $stat->average = 0;
    $stat->min = 0;
    $stat->max = 0;

    // Count validated vs pending.
    $validatedcount = 0;
    $pendingcount = 0;
    foreach ($records as $r) {
        if ($r->status === 'validated') {
            $validatedcount++;
        } else {
            $pendingcount++;
        }
    }
    $stat->validated = $validatedcount;
    $stat->pending = $pendingcount;
    $globalvalidated += $validatedcount;
    $globalpending += $pendingcount;

    if ($stat->count > 0) {
        $grades = array_map(fn($r) => floatval($r->grade), $records);
        $stat->average = array_sum($grades) / $stat->count;
        $stat->min = min($grades);
        $stat->max = max($grades);

        $globalsum += array_sum($grades);
        $globalcount += $stat->count;
        if ($globalmin === null || $stat->min < $globalmin) {
            $globalmin = $stat->min;
        }
        if ($globalmax === null || $stat->max > $globalmax) {
            $globalmax = $stat->max;
        }
    }

    $stat->status = ($pendingcount > 0) ? 'pending' : 'validated';
    $allstats[] = $stat;
}

$globalaverage = $globalcount > 0 ? $globalsum / $globalcount : 0;

// Summary cards.
echo '<div class="row mb-4">';

$summaryCards = [
    ['Devoirs IA', count($allstats), 'primary'],
    ['Total soumissions', $globalcount, 'secondary'],
    ['Moyenne globale', number_format($globalaverage, 2), 'info'],
    ['Min global', $globalmin !== null ? number_format($globalmin, 2) : '-', 'danger'],
    ['Max global', $globalmax !== null ? number_format($globalmax, 2) : '-', 'success'],
];

foreach ($summaryCards as [$label, $value, $color]) {
    echo '<div class="col-md-2">';
    echo '<div class="card text-white bg-' . $color . ' mb-3">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">' . $value . '</h5>';
    echo '<p class="card-text">' . $label . '</p>';
    echo '</div></div></div>';
}

echo '</div>';

// Course health gauge: global validation progress.
$globaltotal = $globalvalidated + $globalpending;
$validationpct = $globaltotal > 0 ? round($globalvalidated / $globaltotal * 100) : 0;

echo '<div class="row mb-4">';
echo '<div class="col-md-4"><div class="card h-100"><div class="card-body text-center">';
echo '<h4>Santé du cours</h4>';
echo '<canvas id="dreamu-health-gauge" height="180" aria-label="Jauge de validation globale" role="img"></canvas>';
echo '<p class="mt-2 mb-0 text-muted">' . $globalvalidated . ' / ' . $globaltotal . ' corrections validées</p>';
echo '</div></div></div>';
echo '<div class="col-md-8"><div class="card h-100"><div class="card-body">';
echo '<h4>Charge de validation par devoir</h4>';
echo '<canvas id="dreamu-load-chart" height="180" aria-label="Validées vs en attente par devoir" role="img"></canvas>';
echo '</div></div></div>';
echo '</div>';

// Assignments table.
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Devoirs avec correction IA</h4>';

// Search + status filter toolbar (client-side, enhances the table below).
echo '<div class="d-flex flex-wrap align-items-center mb-3" style="gap:.5rem">';
echo '<input type="text" id="dreamu-assign-search" class="form-control" style="max-width:280px" '
   . 'placeholder="' . s('🔍 Filtrer un devoir...') . '" aria-label="Filtrer les devoirs">';
echo '<div class="btn-group btn-group-sm" role="group" aria-label="Filtrer par statut">';
echo '<button type="button" id="dreamu-pill-all" class="btn btn-outline-secondary active">Tous</button>';
echo '<button type="button" id="dreamu-pill-validated" class="btn btn-outline-success">Validés</button>';
echo '<button type="button" id="dreamu-pill-pending" class="btn btn-outline-warning">En attente</button>';
echo '</div></div>';

$table = new html_table();
$table->head = ['Devoir', 'Soumissions', 'Moyenne', 'Min', 'Max', 'Statut', 'Actions'];
$table->attributes['class'] = 'generaltable';
$table->id = 'dreamu-assign-table';

foreach ($allstats as $stat) {
    $statusbadge = ($stat->status === 'validated')
        ? '<span class="badge badge-success bg-success">Validé</span>'
        : '<span class="badge badge-warning bg-warning">En attente (' . $stat->pending . ')</span>';

    $avgcolor = '';
    if ($stat->count > 0 && $stat->maxgrade > 0) {
        $ratio = $stat->average / $stat->maxgrade;
        $avgcolor = $ratio >= 0.6 ? 'text-success' : ($ratio >= 0.4 ? 'text-warning' : 'text-danger');
    }

    $actions = '';
    $statsurl = new moodle_url('/local/dreamu_ai/stats.php', ['id' => $stat->cmid]);
    $validateurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $stat->cmid]);
    $actions .= '<a href="' . $statsurl . '" class="btn btn-sm btn-outline-info mr-1">Stats</a>';
    $actions .= '<a href="' . $validateurl . '" class="btn btn-sm btn-outline-success">Valider</a>';

    $table->data[] = [
        format_string($stat->name),
        $stat->count,
        $stat->count > 0 ? '<strong class="' . $avgcolor . '">' . number_format($stat->average, 2) . ' / ' . $stat->maxgrade . '</strong>' : '-',
        $stat->count > 0 ? number_format($stat->min, 2) : '-',
        $stat->count > 0 ? number_format($stat->max, 2) : '-',
        $statusbadge,
        $actions,
    ];
}

// Summary row.
$table->data[] = [
    '<strong>Total / Moyenne globale</strong>',
    '<strong>' . $globalcount . '</strong>',
    '<strong>' . number_format($globalaverage, 2) . '</strong>',
    '<strong>' . ($globalmin !== null ? number_format($globalmin, 2) : '-') . '</strong>',
    '<strong>' . ($globalmax !== null ? number_format($globalmax, 2) : '-') . '</strong>',
    '',
    '',
];

// Mark the summary row so the client-side enhancer keeps it pinned and unfiltered.
$table->rowclasses[count($table->data) - 1] = 'dreamu-summary';

echo html_writer::table($table);

// Client-side table enhancer: live search, status filter pills, sortable columns.
echo '<script>'
   . 'function dreamuEnhanceTable(o){var t=document.getElementById(o.tableId);if(!t)return;var tb=t.tBodies[0];if(!tb)return;'
   . 'function isSum(r){return r.classList.contains("dreamu-summary")||r.textContent.indexOf("Moyenne globale")>-1;}'
   . 'function rows(){return Array.prototype.filter.call(tb.rows,function(r){return !isSum(r);});}'
   . 'var sum=Array.prototype.filter.call(tb.rows,isSum)[0];var f="all";'
   . 'var s=o.searchId?document.getElementById(o.searchId):null;'
   . 'function apply(){var q=(s&&s.value||"").toLowerCase();rows().forEach(function(r){var tx=r.textContent.toLowerCase();var mq=!q||tx.indexOf(q)>-1;var mf=(f==="all");if(!mf&&o.statusCol!=null&&r.cells[o.statusCol]){mf=r.cells[o.statusCol].textContent.toLowerCase().indexOf(f)>-1;}r.style.display=(mq&&mf)?"":"none";});}'
   . 'if(s)s.addEventListener("input",apply);'
   . '(o.pills||[]).forEach(function(p){var e=document.getElementById(p.id);if(!e)return;e.addEventListener("click",function(){f=p.value;(o.pills).forEach(function(pp){var x=document.getElementById(pp.id);if(x)x.classList.toggle("active",pp.id===p.id);});apply();});});'
   . 'var hr=t.tHead?t.tHead.rows[0]:t.rows[0];if(hr){Array.prototype.forEach.call(hr.cells,function(th,i){if(o.noSort&&o.noSort.indexOf(i)>-1)return;th.style.cursor="pointer";th.setAttribute("title","Trier");var d=1;th.addEventListener("click",function(){d=-d;var rs=rows();rs.sort(function(a,b){var x=a.cells[i]?a.cells[i].textContent.trim():"",y=b.cells[i]?b.cells[i].textContent.trim():"";var nx=parseFloat(x.replace(",","."));var ny=parseFloat(y.replace(",","."));if(!isNaN(nx)&&!isNaN(ny))return (nx-ny)*d;return x.localeCompare(y,"fr")*d;});rs.forEach(function(r){tb.appendChild(r);});if(sum)tb.appendChild(sum);Array.prototype.forEach.call(hr.cells,function(h){var b=h.querySelector(".dreamu-arrow");if(b)b.remove();});var ar=document.createElement("span");ar.className="dreamu-arrow";ar.textContent=d>0?" ▲":" ▼";th.appendChild(ar);});});}}'
   . 'dreamuEnhanceTable({tableId:"dreamu-assign-table",searchId:"dreamu-assign-search",statusCol:5,noSort:[6],pills:[{id:"dreamu-pill-all",value:"all"},{id:"dreamu-pill-validated",value:"validé"},{id:"dreamu-pill-pending",value:"en attente"}]});'
   . '</script>';

echo '</div></div>';

// --- Chart.js initialisation (data injected from PHP) ---
$loadlabels = [];
$loadvalidated = [];
$loadpending = [];
foreach ($allstats as $stat) {
    $loadlabels[] = shorten_text($stat->name, 24);
    $loadvalidated[] = $stat->validated;
    $loadpending[] = $stat->pending;
}
$dashpayload = [
    'pct'       => $validationpct,
    'validated' => $globalvalidated,
    'pending'   => $globalpending,
    'labels'    => $loadlabels,
    'vdata'     => $loadvalidated,
    'pdata'     => $loadpending,
];
echo '<script>(function(){var D=' . json_encode($dashpayload, JSON_HEX_TAG | JSON_HEX_AMP) . ';'
   . 'function init(){if(!window.Chart){return setTimeout(init,60);}'
   . 'var g=document.getElementById("dreamu-health-gauge");'
   . 'if(g){new Chart(g,{type:"doughnut",data:{labels:["Validées","En attente"],datasets:[{data:[D.validated,D.pending],backgroundColor:["#198754","#e9ecef"],borderWidth:0}]},options:{responsive:true,cutout:"72%",plugins:{legend:{display:false},tooltip:{enabled:true}}},plugins:[{id:"center",afterDraw:function(c){var x=c.ctx,a=c.chartArea;x.save();x.font="700 28px sans-serif";x.fillStyle="#198754";x.textAlign="center";x.textBaseline="middle";x.fillText(D.pct+"%",(a.left+a.right)/2,(a.top+a.bottom)/2);x.restore();}}]});}'
   . 'var l=document.getElementById("dreamu-load-chart");'
   . 'if(l){new Chart(l,{type:"bar",data:{labels:D.labels,datasets:[{label:"Validées",data:D.vdata,backgroundColor:"#198754"},{label:"En attente",data:D.pdata,backgroundColor:"#ffc107"}]},options:{responsive:true,plugins:{legend:{position:"bottom"}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true,ticks:{precision:0}}}}});}'
   . '}init();})();</script>';

// Back button.
$backurl = new moodle_url('/course/view.php', ['id' => $courseid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Retour au cours</a>';

echo $OUTPUT->footer();
