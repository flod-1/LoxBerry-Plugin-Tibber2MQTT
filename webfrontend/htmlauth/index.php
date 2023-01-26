<?php
require_once "loxberry_web.php";

// Header mit Titel und Hilfe
$L = LBSystem::readlanguage("language.ini");
LBWeb::lbheader("Tibber2MQTT", "", "");

// This is the main area for your plugin
$template_main = new LBTemplate($lbptemplatedir."/main.html");
$template_main->paramArray($L);
$template_main->output();


// Finally print the footer  
LBWeb::lbfooter();
?>
