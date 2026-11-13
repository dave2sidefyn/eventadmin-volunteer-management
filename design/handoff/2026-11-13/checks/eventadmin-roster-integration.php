<?php
// Exercise real WordPress handlers on the local test site; notifications stay disabled.
define('DOING_AJAX', true);
class EventadminTestResponse extends Exception {}
add_filter('wp_die_ajax_handler', fn() => function () { throw new EventadminTestResponse(); });
$mail_count = 0;
add_filter('pre_wp_mail', function($value) use (&$mail_count) { $mail_count++; return false; });
function roster_request($operation, $shift_id, $user_id, $nonce, $notify = false) {
    $_POST = ['operation'=>$operation,'shift_id'=>$shift_id,'user_id'=>$user_id,'nonce'=>$nonce,'show_open'=>'1','notify_volunteer'=>$notify?'1':''];
    ob_start();
    try { eventadmin_ajax_change_shift_roster(); } catch (EventadminTestResponse $e) {}
    $response=json_decode(ob_get_clean(),true);
    if (!is_array($response)) throw new RuntimeException('Invalid AJAX response');
    return $response;
}
function verify_roster($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS: $label\n"; }
$admin=get_user_by('login','claude-test-admin');
$volunteer=get_user_by('login','claude-test-volunteer');
wp_set_current_user($admin->ID);
$shifts=get_posts(['post_type'=>'eventadmin_shift','posts_per_page'=>-1,'title'=>'Auf- und Abbau','meta_query'=>[['key'=>'shift_start','value'=>'2027-09-03T14:00', 'compare'=>'LIKE']]]);
if (count($shifts)!==1) throw new RuntimeException('Test shift not uniquely identified');
$id=$shifts[0]->ID; $key='assigned_user_'.$volunteer->ID;
$nonce=wp_create_nonce('eventadmin_update_shift');
try {
    // Restore the temporary browser test assignment before starting the repeatable cycle.
    if (get_post_meta($id,$key,true)) {
        $res=roster_request('unassign',$id,$volunteer->ID,$nonce);
        verify_roster($res['success'] && !get_post_meta($id,$key,true),'Browser test assignment removed');
    }
    $res=roster_request('assign',$id,$volunteer->ID,'invalid');
    verify_roster(!$res['success'] && !get_post_meta($id,$key,true),'Invalid nonce cannot assign');
    wp_set_current_user($volunteer->ID);
    $res=roster_request('assign',$id,$volunteer->ID,wp_create_nonce('eventadmin_update_shift'));
    verify_roster(!$res['success'] && !get_post_meta($id,$key,true),'Volunteer cannot manage shift roster');
    wp_set_current_user($admin->ID);
    $res=roster_request('assign',$id,$volunteer->ID,$nonce);
    verify_roster($res['success'] && (int)get_post_meta($id,$key,true)===$volunteer->ID,'Manager can add volunteer');
    verify_roster(in_array($volunteer->ID,$res['data']['assigned'],true) && count($res['data']['rows'])>0,'Response includes fresh roster and timeline rows');
    $res=roster_request('assign',$id,$volunteer->ID,$nonce);
    verify_roster(!$res['success'] && count(get_post_meta($id,$key))===1,'Duplicate assignment rejected');
    if (!get_option('eventadmin_allow_overlap')) {
        $other=get_posts(['post_type'=>'eventadmin_shift','posts_per_page'=>-1,'title'=>'Bar Bühne','meta_query'=>[['key'=>'shift_start','value'=>'2027-09-03T14:00','compare'=>'LIKE']]]);
        if (count($other)!==1) throw new RuntimeException('Overlap target missing');
        $res=roster_request('assign',$other[0]->ID,$volunteer->ID,$nonce);
        verify_roster(!$res['success'] && !get_post_meta($other[0]->ID,$key,true),'Configured overlap restriction enforced');
    }
    $res=roster_request('unassign',$id,$volunteer->ID,$nonce,false);
    verify_roster($res['success'] && !get_post_meta($id,$key,true),'Removal without notification does not send email');
    $full=get_posts(['post_type'=>'eventadmin_shift','posts_per_page'=>-1,'title'=>'Bar FC Wyler','meta_query'=>[['key'=>'shift_start','value'=>'2027-09-05T14:00','compare'=>'LIKE']]]);
    if (count($full)!==1 || eventadmin_count_assignments($full[0]->ID)<(int)get_post_meta($full[0]->ID,'max_volunteers',true)) throw new RuntimeException('Full test shift missing');
    $res=roster_request('assign',$full[0]->ID,$volunteer->ID,$nonce);
    verify_roster(!$res['success'] && !get_post_meta($full[0]->ID,$key,true),'Full shift rejects assignment');

    $summary=eventadmin_render_volunteer_summary($volunteer);
    verify_roster(str_contains($summary,'mailto:'.$volunteer->user_email),'Volunteer details email is clickable');
    verify_roster(str_contains($summary,'recipient_user_id='.$volunteer->ID),'Announcement link selects this volunteer');
    $get_roster = function($shift_id) use ($nonce) {
        $_POST=['shift_id'=>$shift_id,'nonce'=>$nonce]; ob_start();
        try { eventadmin_ajax_get_shift_details(); } catch (EventadminTestResponse $e) {}
        $res=json_decode(ob_get_clean(),true);
        return $res['data']['roster_html'];
    };
    $html=$get_roster($id);
    verify_roster(str_contains($html,'class="eventadmin-roster-add-panel" hidden') && str_contains($html,'class="button eventadmin-roster-reveal" aria-expanded="false"'),'Volunteer selection starts collapsed behind Add volunteer');
    verify_roster(str_contains($html,'eventadmin-roster-add-cancel'),'Expanded addition controls include Cancel');

    verify_roster(strpos($html,'eventadmin-roster-notify') < strpos($html,'eventadmin-roster-add"') && str_contains($html,'eventadmin-roster-notify'),'Confirmation checkbox appears above Add to shift');
    $html=$get_roster($full[0]->ID);
    verify_roster(!str_contains($html,'eventadmin-roster-notify') && !str_contains($html,'eventadmin-roster-add'),'Full shift has neither confirmation checkbox nor Add button');
    verify_roster($mail_count===0,'No emails sent');
    $res=roster_request('assign',$id,$volunteer->ID,$nonce);
    verify_roster($res['success'],'Notification test volunteer added');
    $res=roster_request('unassign',$id,$volunteer->ID,$nonce,true);
    verify_roster($res['success'] && $mail_count===1,'Removal notification sent only when explicitly requested (mail transport blocked by test)');

} finally {
    // This test account had no assignment before the browser check.
    delete_post_meta($id,$key);
}
