<?php
/** Extension:NewUserMessage
 *
 * @file
 * @ingroup Extensions
 *
 * @author [http://www.organicdesign.co.nz/nad User:Nad]
 * @license GPL-2.0-or-later
 * @copyright 2007-10-15 [http://www.organicdesign.co.nz/nad User:Nad]
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'NewUserMessage' );
	$wgMessagesDirs['NewUserMessage'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for NewUserMessage extension. ' .
		'Please use wfLoadExtension instead, see ' .
		'https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
} else {
	die( 'This version of the NewUserMessage extension requires MediaWiki 1.25+' );
}
