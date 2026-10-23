<?php
namespace App\Http\Controllers;

class Report
{
    public function show(array $row): string
    {
        return strtolower($row['status']);
    }
}
