<?php

namespace UAMS_Search;

/** One search result, as `Query::run()` returns it. */
final class Result
{
    /**
     * Build a result from an index row.
     *
     * @param  int  $id  The post ID on its own site.
     * @param  int  $site  The site the post belongs to.
     * @param  float  $relevance  The ranking score; higher ranks first.
     * @param  string  $excerpt  HTML excerpt with the matched words in `<mark>`.
     * @param  string  $title  The post title.
     * @param  string  $url  The post's address.
     * @param  string  $post_type  The post type, such as `page` or `attachment`.
     * @param  int  $file_size  The PDF's size in bytes, or 0 for other types.
     * @param  array<string, float>|null  $scores  Each column's score when the
     *                                             search was run with `explain`,
     *                                             otherwise null.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $site,
        public readonly float $relevance,
        public readonly string $excerpt,
        public readonly string $title,
        public readonly string $url,
        public readonly string $post_type,
        public readonly int $file_size,
        public readonly ?array $scores = null
    ) {}
}
