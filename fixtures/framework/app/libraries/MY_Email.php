<?php
class MY_Email extends CI_Email
{
    public function send($auto_clear = TRUE)
    {
        return parent::send($auto_clear);
    }
}
