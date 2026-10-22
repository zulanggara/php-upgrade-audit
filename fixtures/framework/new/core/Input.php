<?php
class CI_Input
{
    public function post($index = null, $xss_clean = false): mixed
    {
        return config_item('new_key');
    }
}
