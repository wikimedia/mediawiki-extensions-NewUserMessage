<?php
/** Extension:NewUserMessage
 *
 * @file
 * @ingroup Extensions
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\NewUserMessage;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Job to create the initial message on a user's talk page
 *
 * Required parameters:
 *   - userId: the user ID
 */
class NewUserMessageJob extends Job implements GenericParameterJob {
	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'newUserMessageJob', $params );
	}

	/** @inheritDoc */
	public function run() {
		$user = User::newFromId( $this->params['userId'] );
		$user->load( IDBAccessObject::READ_LATEST );
		if ( !$user->getId() ) {
			return false;
		}

		NewUserMessage::createNewUserMessage( $user );

		return true;
	}
}
