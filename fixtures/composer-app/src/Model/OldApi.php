<?php
namespace App\Model;

// A library deprecation (E_USER_DEPRECATED), not a PHP one.
trigger_error('Since example/composer-app 2.0: "App\Model\OldApi" is deprecated, use "App\Model\Clean" instead.', E_USER_DEPRECATED);

class OldApi
{
}
