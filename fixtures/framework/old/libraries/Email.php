<?php
class CI_Email
{
    public $verify_peer = TRUE;

    public function send($auto_clear = TRUE)
    {
        $this->verify_peer = FALSE; // local patch
        return TRUE;
    }
}
