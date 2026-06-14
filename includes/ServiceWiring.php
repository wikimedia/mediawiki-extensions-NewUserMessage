<?php
/**
 * Service wiring for Extension:NewUserMessage
 *
 * @file
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\NewUserMessage\NewUserMessage;
use MediaWiki\MediaWikiServices;

return [
	'NewUserMessage.HookHandler' => static function ( MediaWikiServices $services ): NewUserMessage {
		return new NewUserMessage(
			$services->getMainConfig(),
			$services->getUserFactory(),
			$services->getUserEditTracker(),
			$services->getWikiPageFactory(),
			$services->getJobQueueGroup(),
			$services->getTitleFactory(),
			$services->getParser(),
		);
	},
];
