<?php
require_once __DIR__ . '/../includes/db.php';
$c = dbFetchOne("SELECT * FROM cotizaciones WHERE id = 7");
echo "<pre>";
echo "inc_servicios: " . var_export($c['inc_servicios'], true) . " | monto: " . $c['monto_servicios'] . "\n";
echo "inc_amueblado: " . var_export($c['inc_amueblado'], true) . " | monto: " . $c['monto_amueblado'] . "\n";
echo "inc_parking:   " . var_export($c['inc_parking'], true)   . " | monto: " . $c['monto_parking'] . "\n";
echo "inc_mascota:   " . var_export($c['inc_mascota'], true)   . " | monto: " . $c['monto_mascota'] . "\n";
echo "monto_iva:     " . $c['monto_iva'] . "\n";
echo "subtotal:      " . $c['subtotal_mensual'] . "\n";
echo "total_contrato:" . $c['total_contrato'] . "\n";
echo "</pre>";