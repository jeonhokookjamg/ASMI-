<?php header('Content-Type: application/json; charset=utf-8'); require 'config.php';
$a=$_GET['action']??'';
if($a==='buscarPaciente'){ $n=$conn->real_escape_string($_GET['nss']??''); $r=$conn->query("SELECT * FROM pacientes WHERE nss='$n' LIMIT 10"); $d=[]; while($x=$r->fetch_assoc()) $d[]=$x; echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if($a==='stats'){ $p=$conn->query("SELECT COUNT(*) c FROM pacientes")->fetch_assoc()['c']; $pe=$conn->query("SELECT COUNT(*) c FROM personal")->fetch_assoc()['c']; $h=$conn->query("SELECT COUNT(*) c FROM hospitales")->fetch_assoc()['c']; echo json_encode(['pacientes'=>$p,'personal'=>$pe,'hospitales'=>$h]); exit; }
?>