<?php
class WP_Post {
    public int $ID;
    public string $post_type='provider';
    public string $post_status='publish';
    public string $post_password='';
    public string $post_title;
    public function __construct(int $id) { $this->ID=$id; $this->post_title=$id===103?'Dr. <img src=x onerror=alert(1)> Fallback & "Quoted"':'Provider'; }
}
class WP_Term {
    public int $term_id; public string $name;
    public function __construct(int $id,string $name) { $this->term_id=$id; $this->name=$name; }
}
function get_post($id) { return new WP_Post((int)$id); }
function get_post_meta($id,$key,$single=true) {
    $fields=[101=>['physician_first_name'=>'C.','physician_middle_name'=>'Lowry','physician_last_name'=>'Barnes','physician_degree'=>[501],'physician_title'=>502],102=>['physician_first_name'=>'Alex','physician_last_name'=>'Resident','physician_resident'=>1]];
    return $fields[$id][$key]??'';
}
function get_term_meta($id,$key,$single=true) { return $id===502 && $key==='clinical_specialization_title'?'Adult Reconstructive Orthopaedic Surgeon':''; }
function get_term($id,$taxonomy='') { return $id===501?new WP_Term(501,'M.D.'):($id===502?new WP_Term(502,'Orthopaedic Surgeon'):null); }
function is_post_status_viewable($status) { return $status==='publish'; }
function is_post_type_viewable($type) { return true; }
function get_post_thumbnail_id($id) { return $id===101?801:0; }
function wp_get_attachment_image($id,$size,$icon,$attrs) {
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="253" height="337"><rect width="253" height="337" fill="#dedbd8"/></svg>';
    return '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" alt="'.htmlspecialchars($attrs['alt'],ENT_QUOTES).'" width="253" height="337">';
}
function get_permalink($post) { return 'https://uams-search.test/provider/'.$post->ID.'/'; }
$GLOBALS['uams_preview_results']=[
    (object)['id'=>101,'site'=>1,'post_type'=>'provider','title'=>'Barnes','url'=>'https://uams-search.test/provider/101/','excerpt'=>'Provider excerpt must not appear'],
    (object)['id'=>102,'site'=>1,'post_type'=>'provider','title'=>'Resident','url'=>'https://uams-search.test/provider/102/','excerpt'=>'Provider excerpt must not appear'],
    (object)['post_type'=>'page','title'=>'Heart care','url'=>'https://uams-search.test/heart-care/','excerpt'=>'Existing page result excerpt'],
    (object)['id'=>103,'site'=>1,'post_type'=>'provider','title'=>'Fallback','url'=>'https://uams-search.test/provider/103/','excerpt'=>'Provider excerpt must not appear'],
];
require __DIR__.'/render-preview.php';
