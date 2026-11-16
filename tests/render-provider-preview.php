<?php

use UAMS_Search\Result;

class WP_Post
{
    public mixed $post_mime_type = '';

    public mixed $post_parent = 0;

    public mixed $post_content = '';

    public mixed $post_date_gmt = '0000-00-00 00:00:00';

    public mixed $post_modified_gmt = '0000-00-00 00:00:00';

    public string $post_type = 'provider';

    public string $post_status = 'publish';

    public string $post_password = '';

    public string $post_title;

    public function __construct(public int $ID = 0)
    {
        $this->post_type = $GLOBALS['uams_preview_post_types'][$this->ID] ?? 'provider';
        $this->post_title = $GLOBALS['uams_preview_post_titles'][$this->ID] ?? ($this->ID === 103 ? 'Dr. <img src=x onerror=alert(1)> Fallback & "Quoted"' : 'Provider');
    }
}

class WP_Term
{
    public mixed $slug = '';

    public function __construct(public int $term_id, public string $name) {}
}

function get_post(mixed $id): mixed
{
    return new WP_Post((int) $id);
}

function get_post_meta(mixed $id, mixed $key, mixed $_single = true): mixed
{
    $fields = [101 => ['physician_first_name' => 'C.', 'physician_middle_name' => 'Lowry', 'physician_last_name' => 'Barnes', 'physician_degree' => [501], 'physician_title' => 502], 102 => ['physician_first_name' => 'Alex', 'physician_last_name' => 'Resident', 'physician_resident' => 1]];

    return $GLOBALS['uams_preview_fields'][$id][$key] ?? $fields[$id][$key] ?? '';
}

function get_term_meta(mixed $id, mixed $key, mixed $_single = true): mixed
{
    return $id === 502 && $key === 'clinical_specialization_title' ? 'Adult Reconstructive Orthopaedic Surgeon' : '';
}

function get_term(mixed $id, mixed $_taxonomy = ''): ?WP_Term
{
    return $id === 501 ? new WP_Term(501, 'M.D.') : ($id === 502 ? new WP_Term(502, 'Orthopaedic Surgeon') : null);
}

function is_post_status_viewable(mixed $status): bool
{
    return $status === 'publish';
}

function is_post_type_viewable(mixed $_type): bool
{
    return true;
}

function get_post_thumbnail_id(mixed $id): mixed
{
    return $GLOBALS['uams_preview_thumbnails'][$id] ?? ($id === 101 ? 801 : 0);
}

function wp_get_attachment_image(mixed $_id, mixed $_size, mixed $_icon, mixed $attrs): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="253" height="337"><rect width="253" height="337" fill="#dedbd8"/></svg>';

    return '<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" alt="'.htmlspecialchars($attrs['alt'] ?? 'Location exterior', ENT_QUOTES).'" width="253" height="337">';
}

function get_permalink(mixed $post): string|false
{
    return 'https://uams-search.test/'.$post->post_type.'/'.$post->ID.'/';
}

// Results are built before render-preview.php loads the bootstrap, so load the class loader first
require_once __DIR__.'/../includes/autoload.php';
$GLOBALS['uams_preview_results'] ??= [
    new Result(id: 101, site: 1, relevance: 0.0, excerpt: 'Provider excerpt must not appear', title: 'Barnes', url: 'https://uams-search.test/provider/101/', post_type: 'provider', file_size: 0),
    new Result(id: 102, site: 1, relevance: 0.0, excerpt: 'Provider excerpt must not appear', title: 'Resident', url: 'https://uams-search.test/provider/102/', post_type: 'provider', file_size: 0),
    new Result(id: 0, site: 1, relevance: 0.0, excerpt: 'Existing page result excerpt', title: 'Heart care', url: 'https://uams-search.test/heart-care/', post_type: 'page', file_size: 0),
    new Result(id: 103, site: 1, relevance: 0.0, excerpt: 'Provider excerpt must not appear', title: 'Fallback', url: 'https://uams-search.test/provider/103/', post_type: 'provider', file_size: 0),
];
require __DIR__.'/render-preview.php';
