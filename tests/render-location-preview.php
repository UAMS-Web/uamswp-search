<?php
function wp_timezone(){return new DateTimeZone('America/Chicago');}
function current_datetime(){return new DateTimeImmutable('2026-10-30 12:00:00',wp_timezone());}
function get_field($name,$object,$format=true){return $object instanceof WP_Term?get_term_meta($object->term_id,$name,true):get_post_meta($object,$name,true);}
function get_field_object($name,$id,$format=true){return ['choices'=>['2'=>'Second Floor']];}
$GLOBALS['uams_preview_post_types']=[200=>'location',201=>'location',202=>'location',203=>'location'];
$GLOBALS['uams_preview_post_titles']=[200=>'Main Campus',201=>'Child Clinic',202=>'Own Clinic',203=>'Closed & "Quoted" Clinic'];
$GLOBALS['uams_preview_thumbnails']=[200=>900,202=>902,203=>0];
$GLOBALS['uams_preview_fields']=[
 200=>['location_address_1'=>'100 Main Street','location_address_2'=>'Campus Building<br>Suite 200','location_city'=>'Little Rock','location_state'=>'AR','location_zip'=>'72205','location_map'=>['lat'=>34.74,'lng'=>-92.32]],
 201=>['location_parent'=>'1','location_parent_id'=>200,'location_phone'=>'5015264020','location_appointments_query'=>'1','location_clinic_phone_query'=>'1','location_new_appointments_phone'=>'5011112222','location_appointment_phone_query'=>'1','location_return_appointments_phone'=>'5013334444'],
 202=>['location_address_1'=>'20 Another Street','location_city'=>'Fayetteville','location_state'=>'AR','location_zip'=>'72703','location_phone'=>'5015550000 ext. 23','location_appointments_query'=>'0'],
 203=>['location_closing'=>'1','location_closing_length'=>'permanent','location_closing_date'=>'20261029'],
];
$GLOBALS['uams_preview_results']=[
 (object)['id'=>201,'site'=>1,'post_type'=>'location','url'=>'https://uams-search.test/location/201/','excerpt'=>'Old location excerpt'],
 (object)['id'=>202,'site'=>1,'post_type'=>'location','url'=>'https://uams-search.test/location/202/','excerpt'=>'Old location excerpt'],
 (object)['id'=>101,'site'=>1,'post_type'=>'provider','url'=>'https://uams-search.test/provider/101/','excerpt'=>'Old provider excerpt'],
 (object)['id'=>203,'site'=>1,'post_type'=>'location','url'=>'https://uams-search.test/location/203/','excerpt'=>'Old location excerpt'],
];
require __DIR__.'/render-provider-preview.php';
