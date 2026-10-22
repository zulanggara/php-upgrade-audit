<?php
class MY_Input extends CI_Input
{
    public function post($index = NULL, $xss_clean = NULL)
    {
        return parent::post($index, $xss_clean);
    }
}
