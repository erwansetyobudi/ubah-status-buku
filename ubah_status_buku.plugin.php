<?php
/**
 * Plugin Name: Ubah Status Buku
 * Description: Ubah status eksemplar melalui scan barcode dengan log dan laporan.
 * Version: 1.0.4
 * Author: Erwan Setyo Budi
 * Author URI : https://github.com/erwansetyobudi
 * Plugin URI: https://github.com/erwansetyobudi/ubah-status-buku
 */
$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('bibliography', 'Ubah Status Buku', __DIR__ . '/index.php');
