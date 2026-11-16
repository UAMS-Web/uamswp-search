<?php

namespace UAMS_Search;

use UAMS_Search\Tests\Support\StandIn;

require_once __DIR__.'/StandIn.php';

// Code in this namespace calls these in place of the global functions. Each records its key and whether it asked for one value, when a test turns the record on with `uams_test_meta_record`, then reads through the global stand-in.
function get_post_meta(mixed $id, mixed $key, mixed $single = false): mixed
{
    if (StandIn::meta_record()) {
        StandIn::add_meta_read([Cast::string($key), (bool) $single]);
    }

    return \get_post_meta($id, $key, $single);
}

function get_term_meta(mixed $id, mixed $key, mixed $single = false): mixed
{
    if (StandIn::meta_record()) {
        StandIn::add_meta_read([Cast::string($key), (bool) $single]);
    }

    return \get_term_meta($id, $key, $single);
}
