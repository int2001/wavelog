<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/user_guide/general/hooks.html
|
*/

$hook['pre_controller'][] = array(
	'class'    => 'Gettext',
	'function' => 'initialize',
	'filename' => 'Gettext.php',
	'filepath' => 'hooks/gettext',
);

$hook['pre_controller'][] = array(
	'class'    => 'Error_collector',
	'function' => 'register',
	'filename' => 'Error_collector.php',
	'filepath' => 'hooks/errorcollector',
);
