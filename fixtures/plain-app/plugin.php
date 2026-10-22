<?php
/*
 * Plugin Name: Plain Example
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-widget.php';
add_action('init', 'plain_init');
