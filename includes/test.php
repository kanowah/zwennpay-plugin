<?php 

include_once plugin_dir_path(__FILE__) . 'phpqrcode/qrlib.php';

$data = "00020101021126590009mu.maucas0108EZDLMUM002160000MUR00056000103100000000056520458145303480540550.005802MU5916ASYMETRIC CO LTD6014Plaine-Wilhems62310208525786650515ZPMQR00002167746304C188";

// Option 1: Output directly to browser
QRcode::png($data);

// Option 2: Save to plugin uploads folder
$upload_dir = wp_upload_dir(); // WordPress uploads folder
$file = $upload_dir['basedir'] . '/qr_codes/myqr.png';
QRcode::png($data, $file, QR_ECLEVEL_L, 4); // 4 = size


$url = $upload_dir['baseurl'] . '/qr_codes/myqr.png';
echo '<img src="'.esc_url($url).'" alt="QR Code" />';

?>