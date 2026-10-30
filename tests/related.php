<?php
if (!defined('WP_CLI') || !WP_CLI || getenv('UAMS_SEARCH_TESTS') !== '1' || !str_starts_with(DB_NAME,'uams_search_test') || wp_get_environment_type() !== 'local') { throw new RuntimeException('Disposable local database only'); }
use UAMS_Search\{Config,DB,Indexer,Queue,Query,Related,Rebuild,Suggestions};
global $wpdb;
DB::install();
if (Rebuild::active()) { throw new RuntimeException('Cancel the existing test rebuild first'); }
$old = get_site_option('uams_search_settings',[]); $site=get_current_blog_id(); $created=[]; $remote=0; $checks=0;
$check=static function($ok,$name) use (&$checks) { if (!$ok) { throw new RuntimeException($name); } $checks++; WP_CLI::log('PASS: '.$name); };
$token='qrel'.strtolower(wp_generate_password(9,false,false)); $own='qown'.strtolower(wp_generate_password(9,false,false));
$make=static function($type,$title,$extra=[]) use (&$created) { $id=wp_insert_post(array_replace(['post_type'=>$type,'post_status'=>'publish','post_title'=>$title],$extra),true); if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $created[]=[get_current_blog_id(),$id]; return $id; };
$search=static fn($word,$args=[])=>Query::run(array_replace(['s'=>$word,'site'=>$site,'scope'=>'site','suggest'=>false,'per_page'=>100],$args));
$ids=static fn($out)=>array_map(static fn($r)=>(int)$r->id,$out['results']);
$flush=static function() { for($i=0;$i<3;$i++) { Queue::process(1000,20); } };
$clear=static function() use (&$created,$wpdb) { foreach($created as [$blog,$id]) { $wpdb->delete(DB::table('queue'),['site_id'=>$blog,'object_id'=>$id]); } };
try {
    foreach(['provider','location','expertise','condition','treatment'] as $type) { if(!post_type_exists($type)) { register_post_type($type,['public'=>true]); } }
    $config=Config::defaults(); $config['types']=array_intersect_key($config['types'],array_flip(['provider','location','expertise','condition','treatment'])); update_site_option('uams_search_settings',$config);
    $provider=$make('provider','C. Lowry '.$token);
    $private=$make('provider','Private secret'.$token,['post_status'=>'private']);
    $targets=[];
    foreach(Related::FIELDS as $type=>$field) {
        $id=$make($type,'Orthopedics '.$type,['post_content'=>$own]);
        update_post_meta($id,$field,[(string)$provider,$provider,(string)$private]); $targets[$type]=$id;
        $row=Indexer::one($site,$id);
        $check($row['status']==='ready' && str_contains($row['fields'],$token) && !str_contains($row['fields'],'secret'.$token),$type.' indexes linked public provider names without private names');
        $items=json_decode($row['weighted_data'],true); $deps=array_filter($items,static fn($v)=>isset($v['provider_id']));
        $check(count($deps)===2,$type.' deduplicates IDs and retains hidden-provider dependencies');
    }
    Indexer::one($site,$provider); Indexer::one($site,$private);
    $unrelated=$make('location','Unrelated clinic'); Indexer::one($site,$unrelated);
    $bad_a=$make('location','Malformed clinic A'); $bad_b=$make('expertise','Malformed expertise B');
    update_post_meta($bad_a,'physician_locations',[$bad_b]); update_post_meta($bad_b,'physician_expertise',[$bad_a]);
    Indexer::one($site,$bad_a); Indexer::one($site,$bad_b); $clear(); Queue::push($bad_a); $flush();
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id IN (%d,%d)',$site,$bad_a,$bad_b))===0,'Reciprocal wrong-type IDs do not create a queue loop');
    $clear(); $out=$search($token); $found=$ids($out);
    $check($out['total']===5 && !array_diff(array_values($targets),$found),'Provider-name search finds all four related page types');
    $check($found[0]===$provider,'The provider profile ranks above related-only matches');
    $check(!in_array($unrelated,$found,true),'An unrelated clinic does not become a provider-name match');
    $check($ids($search($token,['type'=>'location']))===[$targets['location']],'Location type filtering includes the related clinic');
    $check($search('secret'.$token)['total']===0 && $search($own)['total']===4,'Private dependencies do not hide a page whose text never included their names');
    $check(!array_intersect(array_values($targets),$ids($search((string)$provider))),'Raw relationship IDs are not searchable names');
    $new='renamed'.$token; wp_update_post(['ID'=>$provider,'post_title'=>'C. Lowry '.$new]);
    $check(!array_intersect(array_values($targets),$ids($search($token))),'Live title checks suppress copied old names before the queue runs');
    Queue::process(1000,20);
    foreach($targets as $id) { $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id=%d',$site,$id))===1,'Processing a provider queues related page '.$id); }
    $flush();
    $check($search($new)['total']===5 && $search($token)['total']===0,'Queued refresh replaces provider names on every linked page');
    foreach(['physician_hidden','physician_searchable','_uams_search_exclude','password','private'] as $mode) {
        if($mode==='password') { wp_update_post(['ID'=>$provider,'post_password'=>'fixture-password']); }
        elseif($mode==='private') { wp_update_post(['ID'=>$provider,'post_status'=>'private']); }
        else { update_post_meta($provider,$mode,$mode==='physician_searchable'?'0':'1'); }
        $check($search($new)['total']===0,'Live checks block stale related names for '.$mode);
        $flush();
        $check($search($own)['total']===4,'Queue restores ordinary page searches after '.$mode);
        if($mode==='password') { wp_update_post(['ID'=>$provider,'post_password'=>'']); }
        elseif($mode==='private') { wp_update_post(['ID'=>$provider,'post_status'=>'publish']); }
        else { delete_post_meta($provider,$mode); }
        $flush(); $check($search($new)['total']===5,'Publishing/searchability restoration refreshes related names after '.$mode);
    }
    update_post_meta($targets['location'],'physician_locations',[]); $flush();
    $check($search($new)['total']===4 && !in_array($targets['location'],$ids($search($new)),true),'Removing a provider from a page removes that relationship match');
    update_post_meta($targets['location'],'physician_locations',[$provider]); $flush();
    if(is_multisite()) {
        $remote=wpmu_create_blog('uams-search-test.test','/rel-'.strtolower(wp_generate_password(8,false,false)).'/','Relationship fixture',1,['public'=>1],get_current_network_id());
        if(is_wp_error($remote)) { throw new RuntimeException($remote->get_error_message()); }
        switch_to_blog($remote);
        try {
            $other=$make('provider','Foreign '.'foreign'.$token,['import_id'=>$provider]);
            $other_page=$make('location','Foreign clinic'); update_post_meta($other_page,'physician_locations',[$other]); Indexer::one($remote,$other); Indexer::one($remote,$other_page);
            $check($other===$provider,'The multisite fixture reuses the provider ID');
        } finally { restore_current_blog(); }
        $check($search('foreign'.$token)['total']===0,'Site-scoped provider names do not cross multisite boundaries');
        $network=$search('foreign'.$token,['scope'=>'install']);
        $check($network['total']===2 && !array_filter($network['results'],static fn($r)=>$r->site!==$remote),'Whole-install related names resolve in their own site');
        update_post_meta($provider,'physician_hidden','1');
        $check($search('foreign'.$token,['scope'=>'install'])['total']===2,'Hiding a same-ID local provider does not hide remote related results');
        delete_post_meta($provider,'physician_hidden'); $flush();
    }
    // An edit after staging the target but before publishing must refresh the promoted snapshot.
    $target=$targets['location']; Indexer::one($site,$target,'stage');
    wp_update_post(['ID'=>$provider,'post_title'=>'C. Lowry '.'later'.$token]);
    $staged=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.DB::table('stage').'` WHERE site_id=%d AND object_id=%d',$site,$target),ARRAY_A);
    unset($staged['id']); $wpdb->replace(DB::table(),$staged); $wpdb->delete(DB::table('stage'),['site_id'=>$site,'object_id'=>$target]);
    $flush(); $check(in_array($target,$ids($search('later'.$token)),true),'A queued provider edit refreshes newly promoted staged relationships');
    wp_delete_post($provider,true);
    $check(!array_intersect(array_values($targets),$ids($search('later'.$token))),'Deleted providers cannot leak through stale related-page excerpts');
    $flush(); $check($search($own)['total']===4,'Deleting a provider preserves the related pages and their own searchable content');
    WP_CLI::success($checks.' related-provider integration checks passed.');
} finally {
    foreach($created as [$blog,$id]) {
        if($remote && $blog===$remote) { continue; }
        wp_delete_post($id,true); Suggestions::remove($blog,$id);
        foreach(['index','stage','queue'] as $suffix) { $wpdb->delete(DB::table($suffix),['site_id'=>$blog,'object_id'=>$id]); }
    }
    if($remote && !is_wp_error($remote)) {
        $objects=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT object_id FROM `'.DB::table('word_docs').'` WHERE site_id=%d',$remote)); foreach($objects as $id) { Suggestions::remove($remote,(int)$id); }
        wpmu_delete_blog($remote,true); foreach(['index','stage','queue'] as $suffix) { $wpdb->delete(DB::table($suffix),['site_id'=>$remote]); }
    }
    update_site_option('uams_search_settings',$old);
}
