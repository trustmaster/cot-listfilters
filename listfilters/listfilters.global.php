<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=global
[END_COT_EXT]
==================== */

defined('COT_CODE') or die('Wrong URL');

require_once cot_incfile('listfilters', 'plug');

// Detect filtered category

$c = cot_import('c', 'G', 'TXT');
if (!empty($c))
{
	$listfilters_cat = $c;
}
else
{
	$listfilters_cat = $cfg['plugin']['listfilters']['cat'];
}

?>
