<?php
/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2022 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;

/**
 * Layout variables
 * -----------------
 * @var   array  $displayData  Array with all the given attributes for the image element.
 *                             Eg: src, class, alt, width, height, loading, decoding, style, data-*
 *                             Attribute values are escaped; invalid attribute names are omitted.
 */

if (isset($displayData['alt']) && $displayData['alt'] === false)
{
	unset($displayData['alt']);
}

$attributes = array();

foreach ($displayData as $attributeName => $attributeValue)
{
	if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_:.-]*$/', $attributeName))
	{
		continue;
	}

	$attributes[$attributeName] = htmlspecialchars((string) $attributeValue, ENT_QUOTES, 'UTF-8');
}

echo '<img ' . JArrayHelper::toString($attributes) . '>';
