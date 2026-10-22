<?php
/*
 * Plugin Name: Plain Example
 * Version: 1.2.0
 * Requires PHP: 7.0
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-widget.php';
add_action('init', 'plain_init');
