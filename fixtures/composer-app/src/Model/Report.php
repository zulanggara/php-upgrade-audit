<?php
namespace App\Model;

class Report extends Base
{
    public function handle($input, $format)
    {
        return $format . $input;
    }
}
