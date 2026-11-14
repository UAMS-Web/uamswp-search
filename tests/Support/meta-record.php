<?php

namespace UAMS_Search;

// Code in this namespace calls these in place of the global functions. Each records its key and whether it asked for one value, when a test turns the record on with `uams_test_meta_record`, then reads through the global stand-in.
function get_post_meta($id, $key, $single = false)
{
    if (! empty($GLOBALS['uams_test_meta_record'])) {
        $GLOBALS['uams_test_meta_reads'][] = [$key, $single];
    }

    return \get_post_meta($id, $key, $single);
}

function get_term_meta($id, $key, $single = false)
{
    if (! empty($GLOBALS['uams_test_meta_record'])) {
        $GLOBALS['uams_test_meta_reads'][] = [$key, $single];
    }

    return \get_term_meta($id, $key, $single);
}
