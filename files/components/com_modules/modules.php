<?php
/**
 * @package     Joomla.Site
 * @subpackage  com_modules
 *
 * @copyright   (C) 2015 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

// Load the required admin language files
$lang   = JFactory::getLanguage();
$app    = JFactory::getApplication();
$config = array();
$lang->load('com_modules', JPATH_ADMINISTRATOR);

if (!JFactory::getUser()->authorise('module.edit.frontend', 'com_modules'))
{
	$app->enqueueMessage(JText::_('JERROR_ALERTNOAUTHOR'), 'warning');

	return;
}

if ($app->input->get('task') === 'module.orderPosition')
{
	$config['base_path'] = JPATH_COMPONENT_ADMINISTRATOR;
}

// Trigger the controller
$controller = JControllerLegacy::getInstance('Modules', $config);
$controller->execute($app->input->get('task'));
$controller->redirect();
