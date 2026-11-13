<?php
// Read-only regression checks against the actual plugin functions with a minimal WP shim.
define('ABSPATH', '/tmp/'); define('DAY_IN_SECONDS', 86400);
function get_option($name, $default = false) { return ($GLOBALS['test_options'][$name] ?? ['start_of_week'=>1,'date_format'=>'j F Y','time_format'=>'H:i'][$name] ?? $default); }
function wp_localize_script(...$args) {}
function esc_url_raw($s) { return $s; }
function get_user_locale() { return 'en_US'; }
function wp_json_encode($value) { return json_encode($value); }
function current_time($format) { return '2026-11-10'; }
function sanitize_key($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function __($s, $domain='') { return $s; }
function esc_html__($s, $domain='') { return esc_html($s); }
function esc_attr__($s, $domain='') { return esc_html($s); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function admin_url($s) { return '/wp-admin/'.$s; }
function add_query_arg($args, $url) { return $url.'?'.http_build_query($args); }
function date_i18n($format, $ts) { return gmdate($format, $ts); }
function eventadmin_phone_tel_href($s) { return $s; }
require getcwd().'/includes/admin/date-navigator.php';
require getcwd().'/includes/admin/dashboard-tab-calendar.php';
require getcwd().'/includes/admin/dashboard-tab-table.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$stats=['2026-09-04'=>['shifts'=>3,'status'=>'open'],'2027-09-03'=>['shifts'=>2,'status'=>'open']];
$_GET=['filter_date'=>'2026-09-04','cal_anchor'=>'2027-09-03','cal_mode'=>'week'];
check(eventadmin_calendar_parse_state(true,$stats)===['mode'=>'week','anchor'=>'2026-09-04'],'Selected day must win over inherited anchor');
$_GET=['cal_anchor'=>'2026-09-04'];
check(eventadmin_calendar_parse_state(true,$stats)['anchor']==='2026-09-04','Explicit calendar navigation');
$_GET=[];
check(eventadmin_calendar_parse_state(true,$stats)['anchor']==='2027-09-03','Off-season default still finds next shift');
check(eventadmin_calendar_parse_state(false,$stats)['anchor']==='2027-09-03','Unverified query args ignored');
check(eventadmin_calendar_range('week','2026-09-04')===['2026-08-31','2026-09-06'],'Week respects site week start');
ob_start(); eventadmin_render_when_control([], 'table', '2026-11-18', $stats, 'future'); $out=ob_get_clean();
check(str_contains($out,'Wed 18 Nov'),'An empty selected date must remain visible');
check(!str_contains(substr($out,0,strpos($out,'</button>')),'Upcoming'),'An empty selected date must not be labeled Upcoming');
$rows=[['category'=>'Dept','shift'=>'Test & Shift','shift_id'=>10,'period'=>'Custom date 4 September','capacity'=>'1/2','name'=>'<script>Test</script>','email'=>'test@example.test','phone'=>'123','open'=>false],['category'=>'Dept','shift'=>'Test & Shift','shift_id'=>10,'period'=>'Custom date 4 September','capacity'=>'1/2','name'=>'','email'=>'','phone'=>'','open'=>true]];
ob_start(); eventadmin_render_table_view($rows,[],'table','date','ASC'); $out=ob_get_clean();
check(substr_count($out,'<tr')===3,'Roster retains separate assignment and open-slot rows');
check(substr_count($out,'eventadmin-open-slot-add')===1,'Open slot remains assignable');
check(!str_contains($out,'<script>Test</script>') && str_contains($out,'&lt;script&gt;Test'),'Roster names escaped');
check(str_contains($out,'Custom date 4 September'),'Roster preserves formatted period');
check(str_contains($out,'test@example.test'),'Roster retains contact information');
echo "12 date, detailed-roster and escaping checks passed\n";

function eventadmin_get_option_defaults() { return ['eventadmin_shift_date_format'=>'l, j. F Y, H:i','eventadmin_shift_time_format'=>'H:i']; }
$helpers=file_get_contents(getcwd().'/includes/helpers.php');
$begin=strpos($helpers,'function eventadmin_get_formatted_zeitraum(');
$end=strpos($helpers,"\n}",$begin)+2;
eval(substr($helpers,$begin,$end-$begin));
$GLOBALS['test_options']=['eventadmin_shift_date_format'=>'d/m/Y H:i','eventadmin_shift_time_format'=>'g:i a'];
check(eventadmin_get_formatted_zeitraum('2027-09-03 14:00:00','2027-09-03 18:00:00')==='03/09/2027 14:00 – 6:00 pm','Custom start and end format remain authoritative');
$GLOBALS['test_options']=['eventadmin_shift_date_format'=>'Y.m.d \a\t H:i','eventadmin_shift_time_format'=>'H:i'];
check(eventadmin_get_formatted_zeitraum('2027-09-03 14:00:00','')==='2027.09.03 at 14:00','Custom literals and start-only rendering preserved');
echo "2 custom shift-format checks passed\n";

check(str_contains($out, 'href="mailto:test@example.test"'), 'Table email uses mailto');
check(str_contains($out, 'href="tel:123"'), 'Table phone uses tel');
echo "2 clickable table contact checks passed\n";
