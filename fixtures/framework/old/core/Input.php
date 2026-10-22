<?php
class CI_Input
{
    public function post($index = NULL, $xss_clean = NULL)
    {
        return config_item('old_only_key');
    }

    public function _clean_input_keys($str)
    {
        return $str;
    }
}
