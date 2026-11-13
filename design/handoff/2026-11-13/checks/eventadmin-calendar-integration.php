<?php
function calendar_check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
$admin=get_user_by('login','claude-test-admin'); wp_set_current_user($admin->ID);
$volunteer=get_user_by('login','claude-test-volunteer');
$GLOBALS['eventadmin_calendar_fixture_ids']=[];
function calendar_fixture($title,$start,$end) {

 $id=wp_insert_post(['post_type'=>'eventadmin_shift','post_status'=>'publish','post_title'=>$title]);
 if (is_wp_error($id)||!$id) throw new RuntimeException('Fixture creation failed');
 $GLOBALS['eventadmin_calendar_fixture_ids'][]=$id;
 update_post_meta($id,'shift_start',$start); update_post_meta($id,'shift_end',$end);
 update_post_meta($id,'min_volunteers',1); update_post_meta($id,'max_volunteers',3);
 return $id;
}
if (($args[0] ?? '') === 'cleanup-tests') {
 foreach (['UX test — multi-day shift','UX test — last minute'] as $title) {
  foreach (get_posts(['post_type'=>'eventadmin_shift','posts_per_page'=>-1,'title'=>$title]) as $post) {
   $start=get_post_meta($post->ID,'shift_start',true);
   if (!in_array($start,['2028-03-25T22:00','2028-04-01T23:59:30'],true)) throw new RuntimeException('Unexpected fixture date');
   wp_delete_post($post->ID,true); echo 'Removed test fixture '.$post->ID."\n";
  }
 }
 return;
}
if (($args[0] ?? '') === 'preview') {
 $id=calendar_fixture('Multi-day test shift','2028-03-25T18:00','2028-03-28T06:00');
 add_post_meta($id,'assigned_user_'.$volunteer->ID,$volunteer->ID);
 file_put_contents('/tmp/eventadmin-calendar-preview-id', (string)$id);
 echo 'Preview shift '.$id."\n";
 return;
}
if (($args[0] ?? '') === 'cleanup') {
 $id=(int)file_get_contents('/tmp/eventadmin-calendar-preview-id');
 if (get_the_title($id)!=='Multi-day test shift') throw new RuntimeException('Unexpected fixture');
 wp_delete_post($id,true);
 echo "Preview removed\n";
 return;
}
try {
 $id=calendar_fixture('UX test — multi-day shift','2028-03-25T22:00','2028-03-28T06:00');
 add_post_meta($id,'assigned_user_'.$volunteer->ID,$volunteer->ID);
 $calendar=eventadmin_get_calendar_shifts('2028-03-25','2028-03-28','',0);
 foreach (['2028-03-25','2028-03-26','2028-03-27','2028-03-28'] as $date) calendar_check(in_array($id,array_column($calendar[$date]??[],'id'),true),'Multi-day shift appears on '.$date);
 $calendar=eventadmin_get_calendar_shifts('2028-03-27','2028-04-02','',$volunteer->ID);
 calendar_check(in_array($id,array_column($calendar['2028-03-27']??[],'id'),true),'Shift starting before visible week is included');
 calendar_check($calendar['2028-03-27'][0]['time']==='↳','Continuation day does not repeat start time');
 calendar_check($calendar['2028-03-27'][0]['period']===eventadmin_get_formatted_zeitraum('2028-03-25T22:00','2028-03-28T06:00'),'Configured full period preserved');
 $calendar=eventadmin_get_calendar_shifts('2028-03-27','2028-04-02','',$admin->ID);
 calendar_check(!in_array($id,array_column($calendar['2028-03-27']??[],'id'),true),'Volunteer filter still applies to multi-day shifts');
 $stats=eventadmin_get_shift_day_stats();
 calendar_check(isset($stats['2028-03-26'],$stats['2028-03-27'],$stats['2028-03-28']),'Date navigator includes continuation days across DST');
 $total=0; $shifts=eventadmin_get_shifts('',1,200,'future','date','ASC',$volunteer->ID,'2028-03-27',$total);
 calendar_check(in_array($id,wp_list_pluck($shifts,'ID'),true),'Day view includes shift on continuation date');
 update_post_meta($id,'shift_end','2028-03-28T00:00');
 $calendar=eventadmin_get_calendar_shifts('2028-03-25','2028-03-28','',0);
 calendar_check(!in_array($id,array_column($calendar['2028-03-28']??[],'id'),true),'Midnight end excludes following day');
 $shifts=eventadmin_get_shifts('',1,200,'future','date','ASC',$volunteer->ID,'2028-03-28',$total);
 calendar_check(!in_array($id,wp_list_pluck($shifts,'ID'),true),'Day view excludes midnight-ended shift');
 $late=calendar_fixture('UX test — last minute','2028-04-01T23:59:30','2028-04-02T00:00');
 $calendar=eventadmin_get_calendar_shifts('2028-04-01','2028-04-01','',0);
 calendar_check(in_array($late,array_column($calendar['2028-04-01']??[],'id'),true),'Last-minute shift included through end of grid');
 calendar_check(eventadmin_shift_occupied_days('2028-04-01T12:00','','2028-04-01','2028-04-02')===['2028-04-01'],'Start-only shift retains original day');
 ob_start(); eventadmin_render_overview_toolbar('eventadmin-shift-manager','timeline',[],'',[],'',[$volunteer],$volunteer->ID,'2028-03-27','future','date','ASC',true,['filter_volunteer'=>$volunteer->ID],[]); $html=ob_get_clean();
 calendar_check(str_contains($html,'name="filter_volunteer"') && str_contains($html,'value="'.$volunteer->ID.'" selected='),'Day view displays selected volunteer filter');
 calendar_check(str_contains($html,'<option value="">Anyone</option>'),'Day view allows resetting to Anyone');
 calendar_check(str_contains($html,'name="filter_state"'),'Day view exposes staffing-status filter');
 $rows=eventadmin_build_overview_rows([get_post($id)],'timeline','empty',true);
 calendar_check($rows['timeline_rows']===[],'Empty status excludes a shift with an assigned volunteer');
 $rows=eventadmin_build_overview_rows([get_post($id)],'timeline','understaffed',true);
 calendar_check(count($rows['timeline_rows'])===3,'Understaffed status includes partially filled shift');
 $rows=eventadmin_build_overview_rows([get_post($id)],'timeline','heavilyunderstaffed',true);
 calendar_check($rows['timeline_rows']===[],'Below-minimum status excludes a shift meeting its minimum');
 $rows=eventadmin_build_overview_rows([get_post($id)],'timeline','full',true);
 calendar_check($rows['timeline_rows']===[],'Full status excludes partially staffed shift');
 ob_start(); eventadmin_render_calendar_view([], 'week', '2028-03-27', '', 0, 'full'); $calendar_html=ob_get_clean();
 calendar_check(!str_contains($calendar_html,'data-shift-id="'.$id.'"'),'Calendar Full filter excludes partially staffed shift');
 update_post_meta($id,'max_volunteers',1);
 foreach (['timeline','table','cards'] as $view) {
  ob_start(); $rows=eventadmin_build_overview_rows([get_post($id)],$view,'full',true); $cards_html=ob_get_clean();
  calendar_check($view==='cards' ? str_contains($cards_html,'UX test') : count($rows[$view==='timeline'?'timeline_rows':'table_rows'])>0,'Full shift included in '.$view);
 }
 ob_start(); eventadmin_render_calendar_view([], 'week', '2028-03-27', '', 0, 'full'); $calendar_html=ob_get_clean();
 calendar_check(str_contains($calendar_html,'data-shift-id="'.$id.'"'),'Calendar Full filter includes fully staffed shift');


 ob_start(); eventadmin_render_overview_toolbar('eventadmin-shift-manager','timeline',[],'',[],'',[$volunteer],0,'2028-03-27','future','date','ASC',true,[],[]); $html=ob_get_clean();
 calendar_check(!str_contains($html,'value="'.$volunteer->ID.'" selected='),'Reset volunteer filter leaves Anyone selected');
} finally { foreach ($GLOBALS['eventadmin_calendar_fixture_ids'] as $id) wp_delete_post($id,true); }
