<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=page.list.query
[END_COT_EXT]
==================== */

defined('COT_CODE') or die('Wrong URL');

if ($sqlfilters && $filterway)
{
	$where['filter'] = '(' . implode(" $filterway ", $sqlfilters) . ')';
	if (is_array($sqlparams))
	{
		$params = array_merge($params, $sqlparams);
	}
}

if ($filters)
{
	$list_url_path = array_merge($list_url_path, array('filters' => $filters));
	$list_url = cot_url('page', $list_url_path);
}

if (!empty($cats) && !empty($sqlcats))
{
	// Overwrite category selection with custom filter
	$where['cat'] = $sqlcats;
	$list_url_path = array_merge($list_url_path, array('cats' => $cats));
	$list_url = cot_url('page', $list_url_path);
}

?>