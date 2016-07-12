<?php
include("OpeninghoursOverlapLogger.php");

$instance = new OpeninghoursOverlapLogger();
$instance->parseParams();
$instance->setConfig();
$instance->prepareDbHandles();
$instance->execute();