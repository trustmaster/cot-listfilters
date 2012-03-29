<?php
/**
 * List filters API
 * 
 * @package listfilters
 * @version 1.2
 * @author Gert Hengeveld
 * @copyright (c) Cotonti Team 2011
 * @license BSD
 */

defined('COT_CODE') or die('Wrong URL.');

/**
 * Returns TRUE if the filter is active and meets the provided value or the last argument is omitted.
 * Returns FALSE if the filter is not active or isn't equal to the last argument.
 * 
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $value Value that was filtered on (optional)
 * @param string $catfilter Custom category filter expression
 * @return bool
 */
function listfilter_active($type, $field, $value = NULL, $catfilter = '')
{
	global $list_url_path;
	$res = (is_null($value) && isset($list_url_path['filters'][$type][$field]))
		|| (!is_null($value) && $list_url_path['filters'][$type][$field] == $value);
	if (!empty($catfilter))
	{
		$res &= $list_url_path['cats'] == $catfilter;
	}
	return $res;
}

/**
 * Builds SQL query conditions based on filters
 * @global CotDB $db
 * @global string $db_pages
 * @param array $filters Filters params
 * @return array
 */
function listfilter_build($filters)
{
	global $db, $db_pages;
	
	$operators = array(
		'eq' => "(%s = %s)",
		'ne' => "(%s != %s)",
		'lt' => '(%s < %s)',
		'lte' => '(%s <= %s)',
		'gt' => '(%s > %s)',
		'gte' => '(%s >= %s)',
		'in' => '(%s IN (%s))',
		'rng' => '(%s BETWEEN %s AND %s)'
	);
	
	$sqlfilters = array();
	$sqlparams = array();
	$fieldexists = array();
	foreach ($filters as $type => $filter)
	{
		$type = strtolower($type);
		if (!is_array($filter)) continue;
		foreach ($filter as $field => $value)
		{
			switch ($type)
			{
				case 'lt':
				case 'lte':
				case 'gt':
				case 'gte':
					$value = cot_import($value, 'D', 'NUM');
					if ($value === null)
					{
						unset($filters[$type][$field]);
						continue 2;
					}
					break;
				case 'in':
					$value = explode(',', cot_import($value, 'D', 'TXT'));
					break;
				case 'rng':
					$value = explode('..', cot_import($value, 'D', 'TXT'));
					if (count($value) != 2)
					{
						unset($filters[$type][$field]);
						continue 2;
					}
					break;
				default:
					$value = cot_import($value, 'D', 'TXT');
					if ($value === null || empty($value))
					{
						unset($filters[$type][$field]);
						continue 2;
					}
					break;
			}
			$field = 'page_' . cot_import($field, 'D', 'ALP');
			if ($fieldexists[$field] === null)
			{
				$fieldexists[$field] = $db->fieldExists($db_pages, $field);
			}
			if (!$fieldexists[$field])
			{
				unset($filters[$type][$field]);
				continue;
			}
			if (!is_array($value)) $value = array($value);
			foreach ($value as &$val)
			{
				$encval = cot_unique();
				$sqlparams['p'.$encval] = $val;
				$val = ":p$encval";
			}
			$sqlfilters[] = ($type == 'rng') ? 
				sprintf($operators[$type], $field, $value[0], $value[1]) : 
				sprintf($operators[$type], $field, implode(',', $value));
		}
	}
	return array($sqlfilters, $sqlparams);
}

/**
 * Builds special category selection filter based on expression.
 * Expression is a comma separated list of category codes to select from.
 * No spaces after comma please. An asterisk means "all sublevels".
 * 
 * @param string $c Current main category
 * @param string $cats Category filter expression
 * @return string 
 */
function listfilter_build_cats($c, $cats)
{
	
	if (empty($cats))
	{
		return empty($c) ? '' : "page_cat = '$c'";
	}
	
	if ($cats == '*')
	{
		// All children flag
		$categories = implode("','", cot_structure_children('page', $c));
		return "page_cat IN ('$categories')";
	}
	
	// Multiple categories
	$cats_list = explode(',', $cats);
	$categories = array();
	foreach ($cats_list as $cat)
	{
		// Asterisk at the end means all children
		if ($cat[mb_strlen($cat)-1] == '*')
		{
			$all = true;
			$cat = mb_substr($cat, 0, -1);
			$categories = array_merge($categories, cot_structure_children('page', $cat, true));
		}
		else
		{
			$all = false;
			$categories = array_merge($categories, array($cat));
		}
	}
	$categories = implode("','", array_unique($categories));
	return "page_cat IN ('$categories')";
}

/**
 * Returns the number of items that would be shown if the filter were applied.
 * 
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $value Value that was filtered on (optional)
 * @param string $cat Filtered category
 * @param string $catfilter Custom category filter expression
 * @return bool
 */
function listfilter_count($type, $field, $value = NULL, $cat = '', $catfilter = '')
{
	global $listfilters_cat, $db, $db_pages, $filters, $filterway;
	if (empty($cat))
	{
		$cat = $listfilters_cat;
	}
	$params = array();
	$where = "WHERE " . listfilter_build_cats($cat, $catfilter);
	$GLOBALS['cfg']['display_errors'] = true;
	if ($value === null)
	{
		if ($filters[$type][$field])
		{
			unset($filters[$type][$field]);
		}
	}
	else
	{
		$filters[$type][$field] = $value;
	}
	list($sqlfilters, $sqlparams) = listfilter_build($filters);
	if ($sqlfilters && $filterway)
	{
		$where .= ' AND (' . implode(" $filterway ", $sqlfilters) . ')';
		$params = array_merge($params, $sqlparams);
	}
	return (int)$db->query("SELECT COUNT(*) FROM $db_pages $where", $params)->fetchColumn();
}

/**
 * Returns the current URL with query parameters for this filter added to it, or
 * if the last argument is omitted, the current URL with this filter removed 
 * from it.
 * 
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $value Value to filter on (optional)
 * @param string $cat Custom category code
 * @param string $catfilter Custom category filter expression
 * @return string
 */
function listfilter_url($type, $field, $value = NULL, $cat = '', $catfilter = '')
{
	global $list_url_path, $listfilters_cat;
	if (isset($list_url_path) && is_array($list_url_path) && empty($cat))
	{
		$params = $list_url_path;
	}
	else
	{
		$params = empty($cat) ? array('c' => $listfilters_cat) : array('c' => $cat, 'cats' => $catfilter);
	}
	
	if ($value === NULL || listfilter_active($type, $field, $value))
	{
		unset($params['filters'][$type][$field]);
	}
	else
	{
		$params['filters'][$type][$field] = $value;
	}
	return cot_url('page', $params);
}

/**
 * Returns the URL query parameter for all currently active filters.
 * 
 * @return string
 */
function listfilter_urlparam()
{
	global $list_url_path;
	return http_build_query(array('filters' => $list_url_path['filters']));
}

/**
 * Returns the current URL without filters.
 * 
 * @return string
 */
function listfilter_plainurl()
{
	global $list_url_path;
	$params = $list_url_path;
	if($params['filters'])
	{
		unset($params['filters']);
	}
	return cot_url('page', $params);
}

/**
 * Wrapper for cot_checkbox()
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $value Value to filter on
 * @param int $default Default checked state (0 or 1)
 * @param string $title Alternative label title (defaults to $value)
 * @return string
 */
function listfilter_form_checkbox($type, $field, $value, $default = 0, $title = NULL)
{
	global $list_url_path;
	if (!function_exists('cot_checkbox')) include cot_incfile('forms');
	if ($title === NULL) $title = $value;
	$chosen = (isset($list_url_path['filters'][$type][$field])) ?
		($list_url_path['filters'][$type][$field] == $value) : (bool)$default;
	return cot_checkbox($chosen, "filters[$type][$field]", $title, 
		'id="filter_'.$type.'_'.$field.'"', $value);
}

/**
 * Wrapper for cot_inputbox()
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $default Default value for the input field
 * @return string
 */
function listfilter_form_inputbox($type, $field, $default = '')
{
	global $list_url_path;
	if (!function_exists('cot_inputbox')) include cot_incfile('forms');
	$value = $list_url_path['filters'][$type][$field];
	if (!$value) $value = $default;
	return cot_inputbox('text', "filters[$type][$field]", $value, 
		'id="filter_'.$type.'_'.$field.'"');
}

/**
 * Wrapper for cot_inputbox() with HTML5 input type 'number'
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param float $min Minimum allowed value
 * @param float $max Maximum allowed value
 * @param float $step Allowed number interval (optional, defaults to 1)
 * @param float $default Default value (optional, defaults to $min)
 * @return string
 */
function listfilter_form_numberbox($type, $field, $min, $max, $step = 1, $default = NULL)
{
	global $list_url_path;
	if (!function_exists('cot_inputbox')) include cot_incfile('forms');
	$value = $list_url_path['filters'][$type][$field];
	if (!$value) $value = ($default !== NULL) ? $default : $min;
	return cot_inputbox('number', "filters[$type][$field]", (float)$value, 
		'min="'.(float)$min.'" max="'.(float)$max.'" step="'.(float)$step.'" id="filter_'.$type.'_'.$field.'"');
}

/**
 * Wrapper for cot_radiobox()
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $options Comma-separated list of options
 * @param string $default Option selected by default
 * @param string $titles Comma-separated list of alternative label titles (defaults to $options)
 * @return string
 */
function listfilter_form_radiobox($type, $field, $options, $default = '', $titles = NULL)
{
	global $list_url_path;
	if (!function_exists('cot_radiobox')) include cot_incfile('forms');
	$options = explode(',', $options);
	$titles = ($titles !== NULL && count($options) == count(explode(',', $titles))) ?
		explode(',', $titles) : $options;
	$chosen = $list_url_path['filters'][$type][$field];
	if (!$chosen) $chosen = $default;
	if ($type == 'in') $chosen = explode(',', $chosen);
	return cot_radiobox($chosen, "filters[$type][$field]", $options, $titles, true, 
		'id="filter_'.$type.'_'.$field.'"');
}

/**
 * Wrapper for cot_inputbox() with HTML5 input type 'range'
 * Note that this doesn't support filter type 'rng', for that you will need to 
 * use JavaScript (jQuery UI for example).
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param float $min Minimum allowed value
 * @param float $max Maximum allowed value
 * @param float $step Allowed number interval (optional, defaults to 1)
 * @param float $default Default value (optional, defaults to $min)
 * @return string
 */
function listfilter_form_rangebox($type, $field, $min, $max, $step = 1, $default = NULL)
{
	global $list_url_path;
	if (!function_exists('cot_inputbox')) include cot_incfile('forms');
	$value = $list_url_path['filters'][$type][$field];
	if (!$value) $value = ($default !== NULL) ? $default : $min;
	return cot_inputbox('range', "filters[$type][$field]", (float)$value, 
		'min="'.(float)$min.'" max="'.(float)$max.'" step="'.(float)$step.'" id="filter_'.$type.'_'.$field.'"');
}

/**
 * Wrapper for cot_selectbox()
 *
 * @param string $type Filter type
 * @param string $field Field name
 * @param string $options Comma-separated list of options
 * @param string $default Option selected by default
 * @param string $titles Comma-separated list of alternative label titles (defaults to $options)
 * @param bool $add_empty Allow empty item in the selection
 * @return string
 */
function listfilter_form_selectbox($type, $field, $options, $default = '', $titles = NULL, $add_empty = true)
{
	global $list_url_path;
	if (!function_exists('cot_selectbox')) include cot_incfile('forms');
	$options = explode(',', $options);
	$titles = ($titles !== NULL && count($options) == count(explode(',', $titles))) ?
		explode(',', $titles) : $options;
	$chosen = $list_url_path['filters'][$type][$field];
	if (!$chosen) $chosen = $default;
	if ($type == 'in') $chosen = explode(',', $chosen);
	return cot_selectbox($chosen, "filters[$type][$field]", $options, $titles, $add_empty, 
		'id="filter_'.$type.'_'.$field.'"');
}
?>
