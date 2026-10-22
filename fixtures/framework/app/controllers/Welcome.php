<?php
class Welcome extends MY_Controller
{
    public function index()
    {
        $this->load->library('encrypt');
        $this->load->helper('legacy');
        $this->db->where_in('id');
        $key = $this->input->_clean_input_keys('name');
        return legacy_thing($key);
    }
}
