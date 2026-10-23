<?php
// CodeIgniter 3 style library: input, session and URI values are NULL when missing.
class Local
{
    public function save()
    {
        $data = json_decode($this->input->post('payload'), true);              // FIND: post() is NULL without the field
        $name = strtolower($this->input->get('name'));                          // FIND
        $role = $this->session->userdata('role');
        if (in_array(strtoupper($role), ['ADMIN', 'OWNER'])) {                  // FIND: via $role
            echo 'ok';
        }
        $id = trim($this->uri->segment(3));                                     // FIND
        $q = $this->input->post('q');
        if ($q !== null) {
            echo strtolower($q);                                                // ok: checked
        }
        $limit = $this->input->get('limit') ?? '10';
        echo intval(trim($limit));                                              // ok: has a default
        echo str_replace('-', ' ', $this->input->post('slug', TRUE));          // FIND: xss flag is not a default
        echo htmlspecialchars($_POST['comment']);                               // FIND (low): no isset() for it
        if (isset($_GET['page'])) {
            echo strlen($_GET['page']);                                         // ok: isset() above
        }
        echo strtolower((string) $this->input->post('x'));                      // ok: cast
        echo strtolower($this->input->post('y') ?? '');                         // ok: default
        echo json_decode(null);                                                 // FIND (high): literal null
        echo trim($_COOKIE['token'] ?? null);                                   // FIND (high): ?? null
        $d = new DateTime($this->input->post('date'));                          // FIND: constructor
        $e = DateTime::createFromFormat('Y-m-d', $this->input->get('day'));     // FIND: static method
        echo strtolower($this->config->item('x'));                              // ok: not a known nullable source
    }
}
