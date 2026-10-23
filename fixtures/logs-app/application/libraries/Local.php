<?php
// CodeIgniter-style library whose values come from the database: the scanner cannot see that they can be NULL.
class Local
{
    public function profile(array $row)
    {
        return json_decode($row['settings'], true);
    }
}
