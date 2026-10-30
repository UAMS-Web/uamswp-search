<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('UAMS_SEARCH_TESTS')!=='1'||!str_starts_with(DB_NAME,'uams_search_test')||wp_get_environment_type()!=='local'){throw new RuntimeException('Disposable local test database only');}
use UAMS_Search\{Config,DB,LocationCard,Integration,Suggestions};
global $wpdb,$wp_query;
DB::install();$site=get_current_blog_id();$old_config=get_site_option('uams_search_settings',[]);$old_query=$wp_query;$old_post=$GLOBALS['post']??null;$old_timezone=get_option('timezone_string');$created=[];$terms=[];$remote=0;$checks=0;$token=strtolower(wp_generate_password(8,false,false));
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException($name);} $checks++;WP_CLI::log('PASS: '.$name);};
$make=static function($type,$title,$extra=[])use(&$created){$id=wp_insert_post(array_replace(['post_type'=>$type,'post_status'=>'publish','post_title'=>$title],$extra),true);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$created[]=[get_current_blog_id(),$id];return $id;};
$result=static fn($id,$blog=null)=>(object)['id'=>$id,'site'=>$blog??get_current_blog_id(),'post_type'=>'location','url'=>'https://example.test/stale/','title'=>'Indexed snapshot','excerpt'=>'Old location excerpt'];
$set=static function($id,$values){foreach($values as $key=>$value){update_post_meta($id,$key,$value);}};
$image=static function($owner,$label)use(&$created,$site){$id=wp_insert_attachment(['post_title'=>$label,'post_status'=>'inherit','post_mime_type'=>'image/png'],'',$owner);$created[]=[$site,$id];update_post_meta($id,'_wp_attached_file',$label.'.png');wp_update_attachment_metadata($id,['width'=>512,'height'=>288,'file'=>$label.'.png','sizes'=>[]]);set_post_thumbnail($owner,$id);return $id;};
try{
 foreach(['location','provider']as$type){if(!post_type_exists($type)){register_post_type($type,['public'=>true]);}}
 if(!taxonomy_exists('building')){register_taxonomy('building','location',['public'=>true]);}
 update_site_option('uams_search_settings',Config::defaults());update_option('timezone_string','America/Chicago');
 $parent=$make('location','Parent & "Campus"');$child=$make('location','Child Clinic');$page=$make('page','Ordinary page');$GLOBALS['post']=get_post($page);$caller=$GLOBALS['post'];
 $set($parent,['location_address_1'=>'100 Main Street','location_address_2'=>"Campus Building<br>Suite 200",'location_city'=>'Little Rock','location_state'=>'AR','location_zip'=>'72205','location_map'=>['lat'=>34.74,'lng'=>-92.32],'location_prepend_the'=>'1','location_phone'=>'5011111111']);
 $set($child,['location_parent'=>'1','location_parent_id'=>$parent,'location_address_1'=>'Child address','location_city'=>'Child city','location_phone'=>'(501) 526-4020','location_appointments_query'=>'1']);
 $parent_photo=$image($parent,'parent-photo-'.$token);$child_photo=$image($child,'child-photo-'.$token);
 $card=LocationCard::data($result($child));
 $check($card['name']==='Child Clinic'&&$card['url']===get_permalink($child),'Location cards use the live child title and permalink');
 $check($card['parent']['name']==='Parent & "Campus"'&&$card['parent']['prefix']==='Part of the ','Parent links honor the prepend-the setting');
 $check($card['address']===['100 Main Street','Campus Building','Suite 200','Little Rock, AR 72205'],'Child cards inherit the full parent address and preserve legacy line breaks');
 $check(str_contains($card['image'],'parent-photo-'.$token),'Child cards inherit the parent photo by default');
 $check($card['phones']['numbers'][0]['text']==='501-526-4020'&&!str_contains(wp_json_encode($card['phones']),'501-111-1111'),'Phone numbers belong to the child, not the inherited address location');
 $check(str_contains(rawurldecode($card['directions']),'34.74,-92.32'),'Directions use the inherited location coordinates');
 $check($GLOBALS['post']===$caller&&$wp_query===$old_query&&get_current_blog_id()===$site,'Local card construction preserves caller post, query, and site');
 $set($child,['location_image_override_parent'=>'1','location_image_override_parent_featured'=>'1']);
 $check(str_contains(LocationCard::data($result($child))['image'],'child-photo-'.$token),'Both override flags select the child photo');
 update_post_meta($child,'location_image_override_parent_featured','0');
 $check(str_contains(LocationCard::data($result($child))['image'],'parent-photo-'.$token),'One override flag alone retains the parent photo');
 $set($child,['location_clinic_phone_query'=>'1','location_new_appointments_phone'=>'+1 (501) 111-2222','location_appointment_phone_query'=>'1','location_return_appointments_phone'=>'5013334444']);
 $phone=LocationCard::data($result($child))['phones'];
 $check($phone['heading']==='Appointment Phone Numbers'&&array_column($phone['numbers'],'label')===['New Patients','Returning Patients'],'Separate appointment numbers retain the correct patient labels');
 $check($phone['numbers'][0]['url']==='tel:+15011112222'&&$phone['numbers'][1]['text']==='501-333-4444','Phone display and dial targets preserve the configured digits');
 update_post_meta($child,'location_appointment_phone_query','0');
 $check(LocationCard::data($result($child))['phones']['numbers'][0]['label']==='New and Returning Patients','A single appointment number serves both patient groups');
 update_post_meta($child,'location_appointments_query','0');
 $phone=LocationCard::data($result($child))['phones'];
 $check($phone['heading']==='General Information'&&$phone['numbers'][0]['text']==='501-526-4020','A location without appointments shows its general information number');
 $set($child,['location_appointments_query'=>'1','location_ac_query'=>'1','location_ac_appointments_query'=>'1','location_ac_appointments_primary'=>'5015550001','location_ac_appointments_specialty'=>'5015550002']);
 $check(array_column(LocationCard::data($result($child))['phones']['numbers'],'label')===['Primary Care','Specialty Care'],"Children's locations retain primary and specialty care phone labels");
 $set($child,['location_ac_query'=>'0','location_clinic_phone_query'=>'0','location_phone'=>'501-526-4020 ext. 123']);
 $check(LocationCard::data($result($child))['phones']['numbers'][0]['url']==='tel:5015264020;ext=123','Phone extensions are retained in the dial link');
 update_post_meta($child,'location_phone','<script>alert(1)</script>5015551234');
 $check(LocationCard::data($result($child))['phones']['numbers']===[],'Invalid phone markup is omitted rather than converted into another number');
 update_post_meta($child,'location_phone','5015264020');
 $today=current_datetime()->setTime(0,0);$set($child,['location_closing'=>'1','location_closing_length'=>'permanent','location_closing_date'=>$today->modify('-1 day')->format('Ymd')]);
 $check(LocationCard::data($result($child))['notice']['text']==='This location is permanently closed.','Current permanent closures show a notice');
 update_post_meta($child,'location_closing_date',$today->modify('+2 days')->format('Ymd'));
 $check(str_contains(LocationCard::data($result($child))['notice']['text'],$today->modify('+2 days')->format('F j, Y')),'Future closures use the correct local calendar date');
 $set($child,['location_closing_length'=>'temporary','location_closing_date'=>$today->modify('-1 day')->format('Ymd'),'location_reopen_date'=>$today->format('Ymd')]);
 $check(LocationCard::data($result($child))['notice']===null,'A temporary closure ends on its reopening date');
 update_post_meta($child,'location_reopen_date','');
 $check(LocationCard::data($result($child))['notice']['text']==='This location is temporarily closed.','A temporary closure without an end date stays visible');
 update_post_meta($child,'location_closing','0');
 $hours=['location_modified_hours'=>'1','location_modified_hours_start_date'=>$today->modify('+3 days')->format('Ymd'),'location_modified_hours_end'=>'0'];update_post_meta($child,'location_hours_group',$hours);
 $check(str_contains(LocationCard::data($result($child))['notice']['text'],$today->modify('+3 days')->format('F j, Y')),'Upcoming modified hours are shown within the next 30 days');
 $hours['location_modified_hours_start_date']=$today->modify('+31 days')->format('Ymd');update_post_meta($child,'location_hours_group',$hours);
 $check(LocationCard::data($result($child))['notice']===null,'Modified hours more than 30 days away are not announced early');
 $hours=['location_modified_hours'=>'1','location_modified_hours_start_date'=>$today->modify('-5 days')->format('Ymd'),'location_modified_hours_end'=>'1','location_modified_hours_end_date'=>$today->modify('-1 day')->format('Ymd')];update_post_meta($child,'location_hours_group',$hours);
 $check(LocationCard::data($result($child))['notice']===null,'Expired modified hours are omitted');
 $hours=['location_modified_hours'=>'1','location_modified_hours_start_date'=>"bad\0date",'location_modified_hours_end'=>'0'];update_post_meta($child,'location_hours_group',$hours);
 $check(LocationCard::data($result($child))['notice']===null,'Malformed dates cannot break the result page');
 $key_hours=['location_modified_hours'=>'1','location_modified_hours_start_date'=>$today->format('Ymd'),'location_modified_hours_end'=>'0'];$raw=[];foreach($key_hours as$key=>$value){$raw['field_'.$key]=$value;update_post_meta($child,'location_hours_group_'.$key,$value);}update_post_meta($child,'location_hours_group',$raw);
 $check(LocationCard::data($result($child))['notice']['text']==="This location's hours have been temporarily modified.",'Field-key-shaped raw ACF groups resolve through saved prefixed subfields');foreach($key_hours as$key=>$value){delete_post_meta($child,'location_hours_group_'.$key);}
 $check(LocationCard::data($result($child))['notice']['text']==="This location's hours have been temporarily modified.",'Raw field-key groups also work without duplicate prefixed metadata');
 $parsed=(new ReflectionMethod(LocationCard::class,'date'))->invoke(null,'20261030');$check($parsed->format('P H:i:s')==='-05:00 00:00:00','Raw dates resolve to midnight in the configured WordPress timezone');
 $hours=['location_telemed_modified_hours_query'=>'1','location_telemed_modified_hours_start_date'=>$today->format('Ymd'),'location_telemed_modified_hours_end'=>'0'];update_post_meta($child,'location_hours_group',$hours);
 $check(LocationCard::data($result($child))['notice']['text']==="This location's hours have been temporarily modified.",'Telemedicine modified hours are included');
 update_post_meta($parent,'location_map',['lat'=>0,'lng'=>0]);
 $check(str_ends_with(LocationCard::data($result($child))['directions'],'0%2C0'),'Zero coordinates are valid map values');
 update_post_meta($parent,'location_map',['lat'=>91,'lng'=>-92]);
 $check(LocationCard::data($result($child))['directions']==='','Out-of-range map coordinates do not create directions links');
 update_post_meta($parent,'location_hidden','1');$card=LocationCard::data($result($child));
 $check($card['parent']===null&&$card['address']===['Child address','Child city']&&!str_contains($card['image'],'parent-photo-'.$token),'A hidden parent cannot expose its title, inherited address, or photo');delete_post_meta($parent,'location_hidden');
 wp_update_post(['ID'=>$parent,'post_status'=>'private']);
 $check(LocationCard::data($result($child))['parent']===null,'A private parent is not exposed');wp_update_post(['ID'=>$parent,'post_status'=>'publish']);
 update_post_meta($child,'location_hidden','1');$check(LocationCard::data($result($child))===null,'A hidden child location is omitted');delete_post_meta($child,'location_hidden');
 $fallback=$make('location','Fallback clinic');$check(LocationCard::data($result($fallback))['address']===[],'A location with missing optional data still has a usable card');
 $card=LocationCard::data($result($child));$card['name']='<script>alert(1)</script> & "Clinic"';$card['parent']['name']='<img src=x onerror=alert(1)>';$card['address']=['<script>alert(2)</script>'];$card['notice']['text']='<img src=x onerror=alert(2)>';
 ob_start();LocationCard::render($card);$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
 $check($xp->query('//script|//*[@onerror]')->length===0,'Titles, parent names, addresses, and notices are escaped');
 $check($xp->query('//a[@target="_blank" and contains(@rel,"noopener")]')->length===0,'Invalid map values leave no broken external action');
 $provider=$make('provider','Profile in mixed results');$payload=['results'=>[(object)['id'=>$provider,'site'=>$site,'post_type'=>'provider','url'=>get_permalink($provider)],$result($child),(object)['post_type'=>'page','title'=>'Ordinary page','url'=>get_permalink($page),'excerpt'=>'Keep this excerpt'],$result($fallback)],'total'=>44,'pages'=>5,'fallback'=>false,'suggestion'=>null];$wp_query=new WP_Query();$wp_query->set('s','Clinic');$wp_query->set('uams_search_results',$payload);
 ob_start();Integration::render();$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$names=[];foreach($xp->query('//article')as$article){$names[]=$article->getAttribute('class');}
 $check(count($names)===4&&str_contains($names[0],'uams-search-provider')&&str_contains($names[1],'uams-search-location')&&$names[2]==='uams-search-result'&&str_contains($names[3],'uams-search-location'),'Provider, location, and text results retain their relevance order');
 $check(str_contains($html,'44 results')&&str_contains($html,'Search result pages')&&str_contains($html,'Keep this excerpt'),'Location cards preserve counts, pagination, and ordinary excerpts');$wp_query=$old_query;
 if(is_multisite()){$remote=wpmu_create_blog('uams-search-test.test','/loc-'.$token.'/','Location card fixture',1,['public'=>1],get_current_network_id());if(is_wp_error($remote)){throw new RuntimeException($remote->get_error_message());}switch_to_blog($remote);try{$other=$make('location','Remote clinic',['import_id'=>$child]);$set($other,['location_address_1'=>'Remote address','location_phone'=>'5015559999','location_appointments_query'=>'0']);$remote_url=get_permalink($other);}finally{restore_current_blog();}$card=LocationCard::data($result($other,$remote));$check($other===$child&&$card['name']==='Remote clinic'&&$card['address']===['Remote address']&&$card['url']===$remote_url,'Same-ID locations resolve fields and links on their own sub-site');$check(get_current_blog_id()===$site&&$GLOBALS['post']===$caller&&get_post($child)->post_title==='Child Clinic','Remote cards restore caller state and preserve the local post cache');}
 WP_CLI::success($checks.' location-card integration checks passed.');
}finally{$wp_query=$old_query;$GLOBALS['post']=$old_post;foreach($created as[$blog,$id]){if($remote&&$blog===$remote){continue;}wp_delete_post($id,true);Suggestions::remove($blog,$id);foreach(['index','stage','queue']as$suffix){$wpdb->delete(DB::table($suffix),['site_id'=>$blog,'object_id'=>$id]);}}if($remote&&!is_wp_error($remote)){wpmu_delete_blog($remote,true);foreach(['index','stage','queue']as$suffix){$wpdb->delete(DB::table($suffix),['site_id'=>$remote]);}}foreach($terms as[$tax,$id]){wp_delete_term($id,$tax);}update_site_option('uams_search_settings',$old_config);update_option('timezone_string',$old_timezone);}
