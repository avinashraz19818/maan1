<?php
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile(__DIR__ . '/config.html');
