<?php
namespace App\Model;

// Like many libraries do for optional dependencies: refuse to load when a package is missing.
if (!interface_exists(\Acme\Missing\ClientInterface::class)) {
    throw new \LogicException('You cannot use "App\Model\Optional" as the "acme/missing" package is not installed. Try running "composer require acme/missing".');
}

class Optional
{
}
