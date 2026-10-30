<?php
if (!defined('WP_CLI') || !WP_CLI || getenv('UAMS_SEARCH_TESTS')!=='1' || !str_starts_with(DB_NAME,'uams_search_test') || wp_get_environment_type()!=='local') { throw new RuntimeException('Disposable local database only'); }
use UAMS_Search\{Config,DB,ProviderCard,Integration,Suggestions};
global $wpdb,$wp_query;
DB::install(); $site=get_current_blog_id(); $old_config=get_site_option('uams_search_settings',[]); $old_query=$wp_query; $old_post=$GLOBALS['post']??null;
$created=[]; $terms=[]; $remote=0; $checks=0; $token=strtolower(wp_generate_password(9,false,false));
$check=static function($ok,$name) use (&$checks) { if(!$ok) { throw new RuntimeException($name); } $checks++; WP_CLI::log('PASS: '.$name); };
$make=static function($type,$title,$extra=[]) use (&$created) { $id=wp_insert_post(array_replace(['post_type'=>$type,'post_status'=>'publish','post_title'=>$title],$extra),true); if(is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $created[]=[get_current_blog_id(),$id]; return $id; };
$result=static fn($id,$blog=null)=>(object)['id'=>$id,'site'=>$blog??get_current_blog_id(),'post_type'=>'provider','title'=>'Index snapshot','url'=>'https://example.test/old/','excerpt'=>'Old provider excerpt'];
try {
    if(!post_type_exists('provider')) { register_post_type('provider',['public'=>true]); }
    foreach(['degree','clinical_title'] as $taxonomy) { if(!taxonomy_exists($taxonomy)) { register_taxonomy($taxonomy,'provider',['public'=>true]); } }
    $config=Config::defaults(); update_site_option('uams_search_settings',$config);
    $degree=wp_insert_term('Medical degree '.$token,'degree'); if(is_wp_error($degree)) { throw new RuntimeException($degree->get_error_message()); } $terms[]=['degree',$degree['term_id']];
    $clinical=wp_insert_term('Clinical term '.$token,'clinical_title'); if(is_wp_error($clinical)) { throw new RuntimeException($clinical->get_error_message()); } $terms[]=['clinical_title',$clinical['term_id']];
    update_term_meta($clinical['term_id'],'clinical_specialization_title','Orthopaedic Surgeon');
    $provider=$make('provider','Old indexed name');
    foreach(['physician_first_name'=>'C.','physician_middle_name'=>'Lowry','physician_last_name'=>'Barnes','physician_pedigree'=>'Jr.','physician_degree'=>[$degree['term_id'],$degree['term_id']],'physician_title'=>$clinical['term_id']] as $k=>$v) { update_post_meta($provider,$k,$v); }
    $page=$make('page','A regular page'); $GLOBALS['post']=get_post($page); $caller=$GLOBALS['post'];
    $card=ProviderCard::data($result($provider));
    $check($card['name']==='C. Lowry Barnes Jr., Medical degree '.$token,'Live name fields and degrees replace the indexed snapshot');
    $check($card['subtitle']==='Orthopaedic Surgeon','Clinical specialization supplies the subtitle');
    $check($card['url']===get_permalink($provider),'Profile links use the current provider permalink');
    $check($GLOBALS['post']===$caller && $wp_query===$old_query && get_current_blog_id()===$site,'Building a local card preserves post, query, and site context');
    delete_term_meta($clinical['term_id'],'clinical_specialization_title');
    $check(ProviderCard::data($result($provider))['subtitle']==='Clinical term '.$token,'Missing specialization falls back to the clinical term name');
    update_post_meta($provider,'physician_resident','1');
    $check(ProviderCard::data($result($provider))['subtitle']==='Resident Physician','Residents use the same subtitle as the reference cards');
    update_post_meta($provider,'physician_resident',['unexpected']);
    $check(ProviderCard::data($result($provider))['subtitle']==='Clinical term '.$token,'Malformed resident values do not label a provider as a resident');
    $fallback=$make('provider','Fallback & "Quoted"');
    $data=ProviderCard::data($result($fallback));
    $check($data['name']==='Fallback & "Quoted"' && $data['subtitle']==='','Missing optional fields use the live post title and omit the subtitle');
    ob_start(); ProviderCard::render($data); $html=ob_get_clean();
    $check(str_contains($html,'No Image') && str_contains($html,'View Profile'),'A missing portrait still renders a usable profile card');
    update_post_meta($fallback,'physician_pedigree','Jr.');
    $check(ProviderCard::data($result($fallback))['name']==='Fallback & "Quoted"','A pedigree alone cannot replace the full post title');
    update_post_meta($fallback,'physician_first_name','Only first');
    $check(ProviderCard::data($result($fallback))['name']==='Fallback & "Quoted"','A partial name preserves the full post title');
    delete_post_meta($fallback,'physician_pedigree');
    update_post_meta($fallback,'physician_first_name',(object)['unsafe'=>'value']); update_post_meta($fallback,'physician_degree',[(object)['id'=>1],'bad']);
    $check(ProviderCard::data($result($fallback))['name']==='Fallback & "Quoted"','Object-valued and invalid optional fields are ignored safely');
    $attachment=wp_insert_attachment(['post_title'=>'Portrait '.$token,'post_status'=>'inherit','post_mime_type'=>'image/png'],'', $provider);
    $created[]=[$site,$attachment]; update_post_meta($attachment,'_wp_attached_file','provider-card-'.$token.'.png');
    wp_update_attachment_metadata($attachment,['width'=>253,'height'=>337,'file'=>'provider-card-'.$token.'.png','sizes'=>[]]);
    set_post_thumbnail($provider,$attachment); $card=ProviderCard::data($result($provider));
    $check(str_contains($card['image'],'provider-card-'.$token.'.png') && str_contains($card['image'],'alt="C. Lowry Barnes'),'Portraits use the correct provider attachment and name');
    $hostile=['name'=>'Name <script>alert(1)</script> & "quoted"','subtitle'=>'<img src=x onerror=alert(1)>','url'=>'javascript:alert(1)','image'=>'<img src="https://example.test/photo.jpg" onerror="alert(1)"><script>alert(1)</script>'];
    ob_start();ProviderCard::render($hostile);$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
    $check($xp->query('//script|//*[@onerror]')->length===0 && !str_contains($html,'href="javascript:'),'Names, clinical titles, links, and image markup are escaped or filtered');
    foreach(['physician_hidden'=>'1','physician_searchable'=>'0','_uams_search_exclude'=>'1'] as $key=>$value) {
        update_post_meta($provider,$key,$value); $check(ProviderCard::data($result($provider))===null,'A live '.$key.' change suppresses the card'); delete_post_meta($provider,$key);
    }
    wp_update_post(['ID'=>$provider,'post_status'=>'private']);$check(ProviderCard::data($result($provider))===null,'A newly private provider is not rendered');wp_update_post(['ID'=>$provider,'post_status'=>'publish']);
    $check(ProviderCard::data($result(0))===null && ProviderCard::data($result(99999999))===null,'Invalid or deleted provider IDs cannot use the caller post as a fallback');
    $out=['results'=>[$result($provider),(object)['post_type'=>'page','title'=>'A regular page','url'=>get_permalink($page),'excerpt'=>'Keep this page excerpt'],$result($fallback)],'total'=>91,'pages'=>10,'fallback'=>false,'suggestion'=>null];
    $wp_query=new WP_Query(); $wp_query->set('s','Barnes');$wp_query->set('uams_search_results',$out);$wp_query->set('paged',2);
    ob_start();Integration::render();$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
    $headings=[];foreach($xp->query('//article//h3') as $heading) { $headings[]=trim($heading->textContent); }
    $check(str_starts_with($headings[0],'C. Lowry Barnes') && $headings[1]==='A regular page' && $headings[2]==='Fallback & "Quoted"','Mixed provider/page results retain their original relevance order');
    $check($xp->query('//div[contains(@class,"uams-search-provider-cards")]')->length===2,'Separated providers use separate card groups without moving results');
    $check(str_contains($html,'91 results') && str_contains($html,'Search result pages'),'Cards preserve the result count and pagination');
    $check(str_contains($html,'Keep this page excerpt') && !str_contains($html,'Old provider excerpt'),'Only provider presentation changes; ordinary excerpts remain');
    $wp_query=$old_query;
    if(is_multisite()) {
        $remote=wpmu_create_blog('uams-search-test.test','/card-'.$token.'/','Card fixture',1,['public'=>1],get_current_network_id());if(is_wp_error($remote)) { throw new RuntimeException($remote->get_error_message()); }
        switch_to_blog($remote);
        try { $other=$make('provider','Remote provider',['import_id'=>$provider]);update_post_meta($other,'physician_first_name','Remote');update_post_meta($other,'physician_last_name','Person');$remote_url=get_permalink($other); }
        finally { restore_current_blog(); }
        $card=ProviderCard::data($result($other,$remote));
        $check($other===$provider && $card['name']==='Remote Person' && $card['url']===$remote_url,'Same-ID providers resolve names and URLs on the result site');
        $check(get_current_blog_id()===$site && $GLOBALS['post']===$caller && get_post($provider)->post_title==='Old indexed name','Remote card rendering restores the caller and leaves the local post cache intact');
        update_blog_details($remote,['archived'=>1]);$check(ProviderCard::data($result($other,$remote))===null && get_current_blog_id()===$site,'Archived sites do not render cards or change context');
    }
    WP_CLI::success($checks.' provider-card integration checks passed.');
} finally {
    $wp_query=$old_query;$GLOBALS['post']=$old_post;
    foreach($created as [$blog,$id]) {
        if($remote && $blog===$remote) { continue; }
        wp_delete_post($id,true);Suggestions::remove($blog,$id);foreach(['index','stage','queue'] as $suffix) { $wpdb->delete(DB::table($suffix),['site_id'=>$blog,'object_id'=>$id]); }
    }
    if($remote && !is_wp_error($remote)) { wpmu_delete_blog($remote,true);foreach(['index','stage','queue'] as $suffix) { $wpdb->delete(DB::table($suffix),['site_id'=>$remote]); } }
    foreach($terms as [$taxonomy,$id]) { wp_delete_term($id,$taxonomy); }
    update_site_option('uams_search_settings',$old_config);
}
