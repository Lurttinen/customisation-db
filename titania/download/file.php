<?php
/**
*
* @package titania
* @version $Id$
* @copyright (c) 2008 phpBB Customisation Database Team
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
define('IN_TITANIA', true);
if (!defined('TITANIA_ROOT')) define('TITANIA_ROOT', './../');
if (!defined('PHP_EXT')) define('PHP_EXT', substr(strrchr(__FILE__, '.'), 1));
require(TITANIA_ROOT . 'common.' . PHP_EXT);
include(TITANIA_ROOT . 'includes/class_download.' . PHP_EXT);

// Add language data
$titania->add_lang('titania_download');

// Request vars
$download_id	= request_var('id', 0);
$contrib_id		= request_var('contrib_id', 0);

// Instantiate a download object
$download = new titania_download($download_id);

try
{
	if ($download_id)
	{
		$download->load();
	}
	else if ($contrib_id)
	{
		$download->load_contrib($contrib_id);
	}
	else
	{
		throw new NoDataFoundException();
	}

	$download->check_access();

	$download->stream();
}
catch (NoDataFoundException $e)
{
	$download->trigger_not_found();
}
catch (DownloadAccessDeniedException $e)
{
	$download->trigger_forbidden();
}
catch (FileNotFoundException $e)
{
	$download->trigger_not_found();
}

$download->trigger_not_found();