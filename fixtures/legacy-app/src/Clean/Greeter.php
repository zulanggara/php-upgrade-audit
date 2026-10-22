<?php
// Code that is already fine on PHP 8.x: the --fail-on test expects no finding here.
declare(strict_types=1);

final class Greeter
{
    public function __construct(private string $name)
    {
    }

    public function greet(): string
    {
        return 'Hello, ' . $this->name;
    }
}
